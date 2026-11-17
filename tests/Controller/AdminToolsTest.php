<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRegistrar;
use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ProfileGenerationFailed;
use App\Artefact\SellerTable;
use App\Repository\ArtefactRepository;
use App\Tests\SchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminToolsTest extends WebTestCase
{
    private const IMAGE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg';

    protected function tearDown(): void
    {
        $this->key('');
        parent::tearDown();
    }

    public function testProfilePrefillsFromGeminiAndDoesNotSave(): void
    {
        $this->key('test-key');
        $client = $this->ownerClient();
        $client->disableReboot();
        $client->getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator(new MockHttpClient([
            $this->gemini([
                'title' => 'Abano de palha',
                'description' => 'Peça leve, com palha trançada.',
                'dimensions' => 'aproximadamente 30 cm',
                'materials' => [['name' => 'Palha', 'note' => 'Clara.']],
                'care' => ['Manusear com cuidado.'],
                'handmade' => 'O trançado começa pela borda.',
                'size' => 'M',
            ]),
        ]), 'test-key', 'gemini-3.8-flash'));

        $client->request('POST', '/admin/records/images', ['_token' => $this->token($client, '/admin/records/new')], ['image' => $this->jpeg()]);
        self::assertResponseIsSuccessful();
        $uploaded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($uploaded);

        $client->request('POST', '/admin/records/profile', [
            '_token' => $this->token($client, '/admin/records/new'),
            'image' => $uploaded['path'],
            'notes' => 'Palha coletada no cerrado.',
        ], ['audio' => $this->wav()]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame('Abano de palha', $payload['profile']['title']);
        self::assertSame('Palha', $payload['profile']['materials'][0]['name']);
        self::assertStringNotContainsString('test-key', (string) $client->getResponse()->getContent());

        $repository = static::getContainer()->get(ArtefactRepository::class);
        self::assertInstanceOf(ArtefactRepository::class, $repository);
        self::assertSame([], $repository->recent());
    }

    public function testMissingKeyAndMissingPhotoAreExplainedInPortuguese(): void
    {
        $this->key('');
        $client = $this->ownerClient();
        $client->request('POST', '/admin/records/images', ['_token' => $this->token($client, '/admin/records/new')], ['image' => $this->jpeg()]);
        $uploaded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($uploaded);

        $client->request('POST', '/admin/records/profile', [
            '_token' => $this->token($client, '/admin/records/new'),
            'image' => $uploaded['path'],
        ]);
        self::assertResponseStatusCodeSame(503);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(ProfileGenerationFailed::UNCONFIGURED, $payload['error']);

        $client->request('POST', '/admin/records/profile', [
            '_token' => $this->token($client, '/admin/records/new'),
            'image' => 'not-a-photo',
        ]);
        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Envie pelo menos uma foto da peça antes de gerar o perfil.', $payload['error']);
    }

    public function testToolsRequireTheOwner(): void
    {
        $client = $this->browser();
        $client->request('POST', '/admin/records/profile');
        self::assertResponseRedirects('/');

        $client->request('POST', '/admin/records/export');
        self::assertResponseRedirects('/');
    }

    public function testEditorOffersTheAssistAndDoesNotExposeTheKey(): void
    {
        $this->key('test-key');
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[data-profile-assist-target="generate"]');
        self::assertSelectorTextContains('button[data-profile-assist-target="generate"]', 'Gerar perfil com IA');
        self::assertSelectorExists('input[type="file"][accept*="audio"]');
        self::assertStringNotContainsString('test-key', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('GEMINI_API_KEY', (string) $client->getResponse()->getContent());
    }

    public function testSelectedArtefactsExportToCsvAndXlsx(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $first = $registrar->create($this->data('Cerâmica de coração', 'Uma vasilha pequena.'));
        $second = $registrar->create($this->data('Banco', 'Peça de madeira.'));

        $crawler = $client->request('GET', '/admin/records');
        self::assertSelectorExists('button[name="format"][value="csv"]');
        self::assertSelectorExists('button[name="format"][value="xlsx"]');
        self::assertSelectorExists('input[name="ids[]"][value="'.$first->getId().'"]');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'csv',
            'ids' => [$first->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Cerâmica de coração', $csv);
        self::assertStringContainsString('Uma vasilha pequena.', $csv);
        self::assertStringContainsString('/uploads/artefacts/'.self::IMAGE, $csv);
        self::assertStringContainsString('/p/'.$first->getPublicToken(), $csv);
        self::assertStringNotContainsString('Banco', $csv);
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('content-type'));

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'xlsx',
            'ids' => [$first->getId(), $second->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $xlsx = (string) $client->getResponse()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertIsString($path);
        file_put_contents($path, $xlsx);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        self::assertIsString($sheet);
        self::assertStringContainsString('Cerâmica de coração', $sheet);
        self::assertStringContainsString('Banco', $sheet);
        foreach (SellerTable::headers() as $header) {
            self::assertStringContainsString($header, $sheet);
        }

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'csv',
            'ids' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Selecione ao menos uma peça');
    }

    private function browser(): KernelBrowser
    {
        $client = static::createClient();
        SchemaBuilder::rebuild(static::getContainer()->get(EntityManagerInterface::class));

        return $client;
    }

    private function ownerClient(): KernelBrowser
    {
        $client = $this->browser();
        $client->loginUser(new InMemoryUser('owner', (string) ($_SERVER['ORIGENS_OWNER_PASSWORD_HASH'] ?? ''), ['ROLE_ADMIN']));

        return $client;
    }

    private function token(KernelBrowser $client, string $path): string
    {
        $crawler = $client->request('GET', $path);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function key(string $value): void
    {
        $_ENV['GEMINI_API_KEY'] = $value;
        $_SERVER['GEMINI_API_KEY'] = $value;
        putenv('GEMINI_API_KEY='.$value);
    }

    /** @param array<string, mixed> $profile */
    private function gemini(array $profile): MockResponse
    {
        return new MockResponse((string) json_encode([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => json_encode($profile, JSON_THROW_ON_ERROR)]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function data(string $title, string $description): ArtefactData
    {
        return ArtefactData::fromSubmitted(
            $title,
            'M',
            1,
            $description,
            '20 cm',
            [['name' => 'Barro', 'note' => 'Queimado.']],
            'Guardar seco.',
            'Feito à mão com calma.',
            [self::IMAGE],
            '2026-10-18',
        );
    }

    private function jpeg(): UploadedFile
    {
        $image = imagecreatetruecolor(8, 10);
        imagejpeg($image, $path = tempnam(sys_get_temp_dir(), 'org'));
        unset($image);

        return new UploadedFile($path, 'pena.jpg', 'image/jpeg', null, true);
    }

    private function wav(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wav');
        self::assertIsString($path);
        file_put_contents($path, 'RIFF'.pack('V', 36).'WAVEfmt '.pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', 0));

        return new UploadedFile($path, 'descricao.wav', 'audio/wav', null, true);
    }
}
