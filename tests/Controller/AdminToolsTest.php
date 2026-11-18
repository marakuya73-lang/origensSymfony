<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRegistrar;
use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ProfileExchange;
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
        $client->getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator(new MockHttpClient(fn () => $this->gemini([
            'title' => 'Abano de palha',
            'description' => 'Peça leve, com palha trançada.',
            'dimensions' => 'aproximadamente 30 cm',
            'materials' => [['name' => 'Palha', 'note' => 'Clara.']],
            'care' => ['Manusear com cuidado.'],
            'handmade' => 'O trançado começa pela borda.',
            'size' => 'M',
        ])), 'test-key', 'gemini-3.8-flash'));

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
        $payload = $this->finishedProfile($client);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame('Abano de palha', $payload['profile']['title']);
        self::assertSame('Palha', $payload['profile']['materials'][0]['name']);
        self::assertFalse($payload['complete']);
        self::assertStringNotContainsString('test-key', (string) $client->getResponse()->getContent());

        $repository = static::getContainer()->get(ArtefactRepository::class);
        self::assertInstanceOf(ArtefactRepository::class, $repository);
        self::assertSame([], $repository->recent());
    }

    public function testConversationKeepsAHandEditedNameAndDoesNotSave(): void
    {
        $this->key('test-key');
        $client = $this->ownerClient();
        $client->disableReboot();
        $client->getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator(new MockHttpClient(fn () => $this->gemini([
            'title' => 'Nome da IA',
            'description' => 'Peça leve.',
            'dimensions' => 'aproximadamente 18 cm',
            'materials' => [['name' => 'Palha', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'P',
            'questions' => ['Qual o cuidado com a água?'],
            'complete' => false,
            'changed' => [],
        ])), 'test-key', 'gemini-3.8-flash'));

        $client->request('POST', '/admin/records/images', ['_token' => $this->token($client, '/admin/records/new')], ['image' => $this->jpeg()]);
        $uploaded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($uploaded);
        $token = $this->token($client, '/admin/records/new');

        $client->request('POST', '/admin/records/profile', [
            '_token' => $token,
            'image' => $uploaded['path'],
            'form' => json_encode(['title' => 'Nome escrito à mão', 'description' => 'Já escrevi isto.'], JSON_THROW_ON_ERROR),
            'touched' => json_encode(['title', 'description'], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->finishedProfile($client);
        self::assertIsArray($payload);
        self::assertSame('Nome escrito à mão', $payload['profile']['title']);
        self::assertSame('Já escrevi isto.', $payload['profile']['description']);
        self::assertSame('aproximadamente 18 cm', $payload['profile']['dimensions']);
        self::assertSame(['Qual o cuidado com a água?'], $payload['questions']);
        self::assertFalse($payload['complete']);

        $client->request('POST', '/admin/records/profile', [
            '_token' => $token,
            'image' => $uploaded['path'],
            'history' => json_encode([['role' => 'assistant', 'text' => 'Qual o cuidado com a água?']], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);
        $denied = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($denied);
        self::assertSame(ProfileExchange::ANSWER_REQUIRED, $denied['error']);

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
        self::assertResponseIsSuccessful();
        $payload = $this->finishedProfile($client);
        self::assertIsArray($payload);
        self::assertSame('failed', $payload['status']);
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
        self::assertSelectorTextContains('button[data-profile-assist-target="generate"]', 'Salvar e gerar com IA');
        self::assertSelectorExists('button[data-profile-assist-target="generate"][type="button"]');
        self::assertSelectorExists('button[data-profile-assist-target="cancel"][hidden]');
        self::assertSelectorExists('button[data-profile-assist-target="generate"]:not([disabled])');
        self::assertSelectorExists('[data-record-duration]');
        self::assertSelectorExists('details.assist-fold');
        self::assertSelectorTextContains('summary.assist-summary', 'Preencher com IA');
        self::assertSelectorTextContains('[data-record-label]', 'Gravar');
        self::assertSelectorExists('.chat[hidden]');
        self::assertSelectorExists('.ai-toast[hidden]');
        self::assertSelectorTextContains('button[data-profile-assist-target="send"]', 'Enviar resposta');
        self::assertSelectorTextContains('.photo-lead legend', 'Foto principal');
        $html = (string) $client->getResponse()->getContent();
        $photo = strpos($html, 'Foto principal');
        $assist = strpos($html, 'Preencher com IA');
        $title = strpos($html, 'name="title"');
        self::assertNotFalse($photo);
        self::assertNotFalse($assist);
        self::assertNotFalse($title);
        self::assertLessThan($assist, $photo);
        self::assertLessThan($title, $assist);
        self::assertSame(1, substr_count($html, 'data-profile-assist-target="send"'));
        self::assertStringNotContainsString('<details class="assist-fold" open', $html);
        self::assertStringNotContainsString('test-key', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('GEMINI_API_KEY', (string) $client->getResponse()->getContent());
    }

    public function testSelectedArtefactsExportToCsvAndXlsx(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $first = $registrar->create($this->data('Cerâmica de coração', 'Uma vasilha pequena.', 125050));
        $second = $registrar->create($this->data('Banco', 'Peça de madeira.'));

        $crawler = $client->request('GET', '/admin/records');
        self::assertSelectorExists('button[name="format"][value="csv"]');
        self::assertSelectorExists('button[name="format"][value="xlsx"]');
        self::assertSelectorExists('input[name="ids[]"][value="'.$first->getId().'"]');
        self::assertStringContainsString('R$ 1.250,50', (string) $client->getResponse()->getContent());
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
        self::assertStringContainsString('1.250,50', $csv);
        self::assertStringContainsString('Uma vasilha pequena.', $csv);
        self::assertStringContainsString('/uploads/artefacts/'.self::IMAGE, $csv);
        self::assertStringContainsString('/p/'.$first->getPublicToken(), $csv);
        self::assertStringNotContainsString('Banco', $csv);
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('content-type'));
        $singleLines = preg_split("/\r\n|\n/", trim($csv)) ?: [];
        $singleTotal = (string) end($singleLines);
        self::assertStringStartsWith('Total;', $singleTotal);
        self::assertStringContainsString('1.250,50', $singleTotal);
        self::assertStringNotContainsString('sem preço', $singleTotal);

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'csv',
            'ids' => [$first->getId(), $second->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $both = (string) $client->getResponse()->getContent();
        $bothLines = preg_split("/\r\n|\n/", trim($both)) ?: [];
        $bothTotal = (string) end($bothLines);
        self::assertStringStartsWith('Total;', $bothTotal);
        self::assertStringContainsString('1 sem preço', $bothTotal);
        self::assertStringContainsString('1.250,50', $bothTotal);

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
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($path);
        self::assertIsString($sheet);
        self::assertIsString($styles);
        self::assertStringContainsString('Cerâmica de coração', $sheet);
        self::assertStringContainsString('1250.50', $sheet);
        self::assertStringContainsString('Banco', $sheet);
        self::assertStringContainsString('<f>SUM(C2:C3)</f>', $sheet);
        self::assertStringContainsString('1 sem preço', $sheet);
        self::assertStringContainsString('R$', $styles);
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

    public function testADraftIsSavedWithItsPublicLinkAndQueuesTheText(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => '',
            'size' => 'M',
            'category' => '1',
            'description' => '',
            'notes' => 'Palha do cerrado.',
            'sale_price' => 'R$ 180,00',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();
        $url = trim($client->getCrawler()->filter('.saved-url')->text());
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);
        self::assertMatchesRegularExpression('#^/p/[a-f0-9]{32}$#', $path);
        self::assertSelectorTextContains('body', 'aguardando IA');

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Peça em registro', $html);
        self::assertStringNotContainsString('180,00', $html);
        self::assertStringNotContainsString('R$', $html);
        self::assertStringContainsString('<img', $html);

        $client->request('GET', '/admin/records');
        self::assertSelectorTextContains('body', 'Gerar novamente');
        self::assertStringContainsString('R$ 180,00', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.backup-warning', 'Há peças gravadas depois deste backup.');
        self::assertStringNotContainsString('Há peças gravadas', $client->getCrawler()->filter('.backup-status')->text());
    }

    public function testSavingAndGeneratingKeepsManualText(): void
    {
        $client = $this->ownerClient();
        $client->disableReboot();
        $client->getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator(new MockHttpClient(fn () => $this->gemini([
            'title' => 'Nome da IA',
            'description' => 'Texto escrito pela IA.',
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => 'Clara.']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'M',
            'symbolism' => 'Cuidado quieto.',
        ])), 'test-key', 'gemini-3.8-flash'));
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->setServerParameter('HTTP_X_ORIGENS_GENERATE', '1');

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Nome dela',
            'size' => 'M',
            'category' => '1',
            'description' => '',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'notes' => 'Uma pedra verde.',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['confirmed']);
        self::assertMatchesRegularExpression('#/p/[a-f0-9]{32}$#', (string) $payload['url']);

        $path = parse_url((string) $payload['url'], PHP_URL_PATH);
        self::assertIsString($path);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nome dela', $html);
        self::assertStringContainsString('Pedra', $html);
        self::assertStringContainsString('Texto escrito pela IA.', $html);
        self::assertStringNotContainsString('Nome da IA', $html);
    }

    public function testACompleteSaveDoesNotQueueAi(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Cocar pronto',
            'size' => 'M',
            'category' => '1',
            'description' => 'Texto já escrito.',
            'materials' => [['name' => 'Pedra', 'note' => '']],
            'care' => 'Guardar seco.',
            'handmade' => 'Feito à mão.',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);
        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();
        self::assertSelectorNotExists('.ai-badge');
    }

    public function testAddingAPhotoDoesNotStartGeneration(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'generate' => '1',
            'title' => '',
            'size' => 'M',
            'category' => '1',
            'description' => '',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertNull($payload['job']);
        $jobs = static::getContainer()->get(\App\Repository\ProfileJobRepository::class);
        self::assertInstanceOf(\App\Repository\ProfileJobRepository::class, $jobs);
        self::assertSame([], $jobs->recent());
    }

    public function testGenerateKeepsTheNotesFromTheButtonAndCancelStopsTheJob(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->setServerParameter('HTTP_X_ORIGENS_GENERATE', '1');

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => '',
            'size' => 'M',
            'category' => '1',
            'description' => '',
            'notes' => 'Palha do cerrado.',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
            'audio' => $this->wav(),
        ]);

        self::assertResponseIsSuccessful();
        $jobs = static::getContainer()->get(\App\Repository\ProfileJobRepository::class);
        self::assertInstanceOf(\App\Repository\ProfileJobRepository::class, $jobs);
        $job = $jobs->recent()[0] ?? null;
        self::assertInstanceOf(\App\Entity\ProfileJob::class, $job);
        self::assertSame('Palha do cerrado.', $job->getNotes());
        self::assertNotNull($job->getAudioPath());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE profile_jobs SET status = :status, run_at = :run WHERE id = :id',
            ['status' => 'queued', 'run' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'), 'id' => $job->getId()],
        );
        $em->clear();

        $client->request('POST', '/admin/records/profile/'.$job->getId().'/cancel', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $em->clear();
        $cancelled = $em->find(\App\Entity\ProfileJob::class, $job->getId());
        self::assertInstanceOf(\App\Entity\ProfileJob::class, $cancelled);
        self::assertSame('cancelled', $cancelled->getStatus());
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

    /** @return array<string, mixed> */
    private function finishedProfile(KernelBrowser $client): array
    {
        $started = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($started);
        self::assertArrayHasKey('job', $started);
        $client->request('GET', '/admin/records/profile/'.$started['job']);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
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

    private function data(string $title, string $description, ?int $salePriceCents = null): ArtefactData
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
            $salePriceCents,
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
