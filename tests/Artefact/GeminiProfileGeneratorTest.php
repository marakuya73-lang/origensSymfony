<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ProfileGenerationFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiProfileGeneratorTest extends TestCase
{
    public function testSendsThePhotoAudioAndNotesAndIgnoresTheModelThought(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->completed([
                'title' => 'Cocar de fibra',
                'description' => 'Uma peça pequena, com fibras claras.',
                'dimensions' => 'aproximadamente 18 cm',
                'materials' => [['name' => 'Fibra', 'note' => 'Clara e seca.']],
                'care' => ['Guardar em local seco.'],
                'handmade' => 'O entrelaçado pede tempo.',
                'size' => 'P',
            ], '```json
{"title":"pensamento"}
```');
        });

        $profile = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', 'audio-bytes', 'audio/webm', 'Veio de uma palmeira.')->profile;

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/interactions', $captured['url']);
        self::assertSame(0, $captured['options']['max_redirects']);
        self::assertStringNotContainsString('test-key', $captured['url']);
        $headers = $captured['options']['normalized_headers']['x-goog-api-key'] ?? [];
        self::assertSame(['x-goog-api-key: test-key'], $headers);
        $body = json_decode((string) $captured['options']['body'], true);
        self::assertIsArray($body);
        self::assertSame('gemini-3.8-flash', $body['model']);
        self::assertSame('image/jpeg', $body['input'][1]['mime_type']);
        self::assertSame(base64_encode('jpeg-bytes'), $body['input'][1]['data']);
        self::assertSame('audio/webm', $body['input'][2]['mime_type']);
        self::assertSame(base64_encode('audio-bytes'), $body['input'][2]['data']);
        self::assertStringContainsString('Veio de uma palmeira.', $body['input'][0]['text']);
        self::assertStringContainsString('poético', $body['system_instruction']);
        self::assertStringContainsString('Não invente procedência', $body['system_instruction']);
        self::assertStringContainsString('espiritual', $body['system_instruction']);
        self::assertStringContainsString('Três materiais ditos são três itens', $body['system_instruction']);
        self::assertStringContainsString('Nunca substitua uma descrição por uma versão mais curta', $body['system_instruction']);
        self::assertStringNotContainsString('O nome é curto, não uma frase.', $body['system_instruction']);
        self::assertSame(16384, $body['generation_config']['max_output_tokens']);
        self::assertSame('low', $body['generation_config']['thinking_level']);
        self::assertStringContainsString('metáfora', $body['response_format']['schema']['properties']['title']['description']);
        self::assertStringContainsString('espiritual', $body['response_format']['schema']['properties']['description']['description']);
        self::assertSame(30, $body['response_format']['schema']['properties']['materials']['maxItems']);
        $schemaFields = array_keys($body['response_format']['schema']['properties']);
        self::assertNotContains('price', $schemaFields);
        self::assertNotContains('sale_price', $schemaFields);
        self::assertNotContains('salePriceCents', $schemaFields);
        self::assertSame('Cocar de fibra', $profile->title);
        self::assertSame('P', $profile->size);
        self::assertSame([['name' => 'Fibra', 'note' => 'Clara e seca.']], $profile->materials);
    }

    public function testKeepsEverySpokenMaterialAndTheWholeStory(): void
    {
        $story = 'Ela contou que a peça nasceu de um sonho à beira do rio. '
            .'A pedra guarda proteção, o cristal abre o caminho e o linho segura a intenção de cura. '
            .'Nada disso deve ser resumido.';
        $client = new MockHttpClient([$this->completed([
            'title' => 'Verso da margem',
            'description' => $story,
            'dimensions' => '',
            'materials' => 'palha, cristal e linho',
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'M',
        ])]);

        $profile = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', 'audio-bytes', 'audio/mp4', 'Palha, cristal e linho.')->profile;

        self::assertSame($story, $profile->description);
        self::assertSame(
            [
                ['name' => 'palha', 'note' => ''],
                ['name' => 'cristal', 'note' => ''],
                ['name' => 'linho', 'note' => ''],
            ],
            $profile->materials,
        );
    }

    public function testSeparateMaterialRowsStaySeparate(): void
    {
        $client = new MockHttpClient([$this->completed([
            'title' => 'Verso da margem',
            'description' => 'A história permanece.',
            'dimensions' => '',
            'materials' => [
                ['name' => 'Palha', 'note' => 'Trançada.'],
                ['name' => 'Cristal', 'note' => 'Branco.'],
                ['name' => 'Linho', 'note' => ''],
            ],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'M',
        ])]);

        $profile = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '')->profile;

        self::assertSame(['Palha', 'Cristal', 'Linho'], array_column($profile->materials, 'name'));
        self::assertSame('Trançada.', $profile->materials[0]['note']);
        self::assertSame('Branco.', $profile->materials[1]['note']);
    }

    public function testMissingKeyDoesNotCallTheApi(): void
    {
        $client = new MockHttpClient(function (): never {
            throw new \RuntimeException('The API key must stay on the server and the call must not happen.');
        });

        $this->expectException(ProfileGenerationFailed::class);
        $this->expectExceptionMessage(ProfileGenerationFailed::UNCONFIGURED);
        $this->generator($client, '  ')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
    }

    public function testUnavailablePrimaryRetriesTheFallbackModelOnce(): void
    {
        $models = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$models): MockResponse {
            $body = json_decode((string) $options['body'], true);
            self::assertIsArray($body);
            $models[] = $body['model'];
            if (count($models) === 1) {
                return new MockResponse('{"error":{"code":503,"status":"UNAVAILABLE","message":"model is currently experiencing high demand"}}', ['http_code' => 503]);
            }

            return $this->completed([
                'title' => 'Cocar de fibra',
                'description' => 'Uma peça pequena.',
                'dimensions' => '',
                'materials' => [['name' => 'Fibra', 'note' => '']],
                'care' => ['Guardar seco.'],
                'handmade' => 'Feito à mão.',
                'size' => 'M',
            ]);
        });

        $profile = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '')->profile;

        self::assertSame(['gemini-3.8-flash', 'gemini-3.5-flash-lite'], $models);
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame('Cocar de fibra', $profile->title);
    }

    public function testRateLimitRetriesOnceThenReportsTheLimit(): void
    {
        $models = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$models): MockResponse {
            $body = json_decode((string) $options['body'], true);
            self::assertIsArray($body);
            $models[] = $body['model'];

            return new MockResponse('{"error":{"status":"RESOURCE_EXHAUSTED","message":"quota"}}', ['http_code' => 429]);
        });

        try {
            $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
            self::fail('A rate limit on both models must fail the generation.');
        } catch (ProfileGenerationFailed $exception) {
            self::assertSame(429, $exception->status());
            self::assertSame(ProfileGenerationFailed::LIMITED, $exception->getMessage());
        }

        self::assertSame(['gemini-3.8-flash', 'gemini-3.5-flash-lite'], $models);
    }

    public function testSecondUnavailableStillFailsWithoutAThirdCall(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":{"status":"UNAVAILABLE","message":"high demand"}}', ['http_code' => 503]),
            new MockResponse('{"error":{"status":"UNAVAILABLE","message":"high demand"}}', ['http_code' => 503]),
            new MockResponse('{"error":{"status":"UNAVAILABLE"}}', ['http_code' => 503]),
        ]);

        try {
            $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
            self::fail('Two unavailable models must fail the generation.');
        } catch (ProfileGenerationFailed $exception) {
            self::assertSame(503, $exception->status());
            self::assertSame(ProfileGenerationFailed::UNAVAILABLE, $exception->getMessage());
        }

        self::assertSame(2, $client->getRequestsCount());
    }

    public function testTheSameModelIsNotCalledTwice(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":{"status":"UNAVAILABLE"}}', ['http_code' => 503]),
        ]);

        try {
            $this->generator($client, 'test-key', 'gemini-3.8-flash', 'gemini-3.8-flash')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
            self::fail('An unavailable model must fail when there is no distinct fallback.');
        } catch (ProfileGenerationFailed $exception) {
            self::assertSame(ProfileGenerationFailed::UNAVAILABLE, $exception->getMessage());
        }

        self::assertSame(1, $client->getRequestsCount());
    }

    public function testRejectedKeyAndTransportErrorsStayInPortuguese(): void
    {
        $rejected = new MockHttpClient([
            new MockResponse('{"error":{"status":"UNAUTHENTICATED"}}', ['http_code' => 401]),
        ]);
        try {
            $this->generator($rejected, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
            self::fail('A rejected key must fail the generation.');
        } catch (ProfileGenerationFailed $exception) {
            self::assertSame(ProfileGenerationFailed::REJECTED_KEY, $exception->getMessage());
        }
        self::assertSame(1, $rejected->getRequestsCount());

        $offline = new MockHttpClient(function (): never {
            throw new TransportException('timed out');
        });
        try {
            $this->generator($offline, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
            self::fail('A transport error must fail the generation.');
        } catch (ProfileGenerationFailed $exception) {
            self::assertSame(ProfileGenerationFailed::UNAVAILABLE, $exception->getMessage());
            self::assertStringNotContainsString('timed out', $exception->getMessage());
        }
    }

    public function testTrimsTheProfileToTheArtefactLimits(): void
    {
        $client = new MockHttpClient([$this->completed([
            'title' => str_repeat('á', 200),
            'description' => '',
            'dimensions' => '',
            'materials' => [],
            'care' => ['  '],
            'handmade' => '',
            'size' => 'enorme',
        ])]);

        $profile = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '')->profile;

        self::assertSame(180, mb_strlen($profile->title));
        self::assertSame('', $profile->description);
        self::assertSame([['name' => 'informação a documentar', 'note' => '']], $profile->materials);

        $long = new MockHttpClient([$this->completed([
            'title' => 'Verso',
            'description' => str_repeat('á', 8001),
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'M',
        ])]);
        $kept = $this->generator($long, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '')->profile;
        self::assertSame(8000, mb_strlen($kept->description));
        self::assertSame(['informação a documentar'], $profile->care);
        self::assertSame('informação a documentar', $profile->handmade);
        self::assertSame('', $profile->size);
    }

    public function testFollowUpCarriesTheConversationAndHandEditedFields(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true);

            return $this->completed([
                'title' => 'Nome novo',
                'description' => 'Peça leve.',
                'dimensions' => 'aproximadamente 18 cm',
                'materials' => [['name' => 'Palha', 'note' => '']],
                'care' => ['Guardar seco.'],
                'handmade' => 'Feito à mão.',
                'size' => 'M',
                'questions' => [],
                'complete' => true,
                'changed' => ['dimensions'],
            ]);
        });

        $turn = $this->generator($client, 'test-key')->suggest(
            'jpeg-bytes',
            'image/jpeg',
            null,
            null,
            '',
            'A dimensão é cerca de 18 cm.',
            [['role' => 'assistant', 'text' => 'Qual a dimensão?']],
            ['title' => 'Nome escrito à mão'],
            ['title'],
        );

        self::assertIsArray($captured);
        self::assertStringContainsString('Conversa até aqui:', $captured['input'][0]['text']);
        self::assertStringContainsString('Qual a dimensão?', $captured['input'][0]['text']);
        self::assertStringContainsString('A dimensão é cerca de 18 cm.', $captured['input'][0]['text']);
        self::assertStringContainsString('Campos editados à mão: title.', $captured['input'][0]['text']);
        self::assertTrue($turn->complete);
        self::assertSame([], $turn->questions);
        self::assertSame(['dimensions'], $turn->changed);
    }

    public function testOpenQuestionsStayShortAndUnknownChangesAreDropped(): void
    {
        $client = new MockHttpClient([$this->completed([
            'title' => 'Cocar',
            'description' => '',
            'dimensions' => '',
            'materials' => [['name' => 'Fibra', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'P',
            'questions' => ['Qual a dimensão?', '  ', 'O material é fibra ou palha?', 'E a origem?'],
            'complete' => false,
            'changed' => ['dimensions', 'preço', 'dimensão'],
        ])]);

        $turn = $this->generator($client, 'test-key')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');

        self::assertFalse($turn->complete);
        self::assertSame(['Qual a dimensão?', 'O material é fibra ou palha?', 'E a origem?'], $turn->questions);
        self::assertSame(['dimensions'], $turn->changed);
    }

    public function testInvalidModelIsRejectedBeforeTheCall(): void
    {
        $client = new MockHttpClient(function (): never {
            throw new \RuntimeException('Invalid model names must not be sent.');
        });

        $this->expectException(ProfileGenerationFailed::class);
        $this->expectExceptionMessage(ProfileGenerationFailed::INVALID_MODEL);
        $this->generator($client, 'test-key', '../gemini')->suggest('jpeg-bytes', 'image/jpeg', null, null, '');
    }

    private function generator(MockHttpClient $client, string $key, string $model = 'gemini-3.8-flash', string $fallback = 'gemini-3.5-flash-lite'): GeminiProfileGenerator
    {
        return new GeminiProfileGenerator($client, $key, $model, $fallback);
    }

    /** @param array<string, mixed> $profile */
    private function completed(array $profile, string $thought = 'pensando'): MockResponse
    {
        return new MockResponse((string) json_encode([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [
                    ['type' => 'text', 'thought' => true, 'text' => $thought],
                    ['type' => 'text', 'text' => json_encode($profile, JSON_THROW_ON_ERROR)],
                ],
            ]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
