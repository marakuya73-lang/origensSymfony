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
        ])), 'test-key', 'gemini-3.8-flash', 'gemini-3.5-flash-lite'));

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
        ])), 'test-key', 'gemini-3.8-flash', 'gemini-3.5-flash-lite'));

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

    public function testSelectedArtefactsExportToPdfAndXlsx(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $photo = dirname(__DIR__, 2).'/var/test-uploads/'.self::IMAGE;
        if (!is_dir(dirname($photo)) && !mkdir(dirname($photo), 0775, true) && !is_dir(dirname($photo))) {
            self::fail('Could not prepare the test photo.');
        }
        $image = imagecreatetruecolor(40, 60);
        imagejpeg($image, $photo);
        unset($image);
        $first = $registrar->create($this->data('Cerâmica de coração', 'Uma vasilha pequena.', 125050));
        $second = $registrar->create(\App\Artefact\ArtefactData::fromSubmitted(
            'Banco',
            'G',
            2,
            'Peça de madeira.',
            '40 cm',
            [['name' => 'Madeira', 'note' => 'Clara.']],
            'Guardar seco.',
            'Feito à mão com calma.',
            ['cccccccc-cccc-4ccc-8ccc-cccccccccccc/dddddddd-dddd-4ddd-8ddd-dddddddddddd.jpg'],
            '2026-10-18',
        ));

        $crawler = $client->request('GET', '/admin/records');
        self::assertSelectorNotExists('button[name="format"][value="csv"]');
        self::assertSelectorExists('button[name="format"][value="pdf"]');
        self::assertSelectorExists('button[name="format"][value="xlsx"]');
        self::assertSelectorExists('input[name="ids[]"][value="'.$first->getId().'"]');
        self::assertSelectorExists('.selection-total[hidden]');
        self::assertSelectorExists('article.record-row[data-cents="125050"]');
        self::assertStringContainsString('selecionados', (string) file_get_contents(dirname(__DIR__, 2).'/assets/controllers/archive_export_controller.js'));
        self::assertStringContainsString('z-index: 2', (string) file_get_contents(dirname(__DIR__, 2).'/assets/styles/app.css'));
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'pdf',
            'ids' => [$first->getId()],
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/pdf', (string) $client->getResponse()->headers->get('content-type'));
        $pdf = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $pdf);
        $html = static::getContainer()->get(SellerTable::class)->catalogueHtml([$first]);
        self::assertStringContainsString('M-CAT1-001', $html);
        self::assertStringContainsString('class="ref"', $html);
        self::assertStringContainsString('Cerâmica de coração', $html);
        self::assertStringContainsString('Categoria 1', $html);
        self::assertStringContainsString('R$ 1.250,50', $html);
        self::assertStringContainsString('R$ 1.625,65', $html);
        self::assertStringContainsString('Preço sugerido', $html);
        self::assertStringContainsString('1 abanilhos', $html);
        self::assertStringContainsString('data:image/jpeg;base64,', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringNotContainsString('Banco', $html);
        self::assertStringContainsString('page-break-inside: avoid', $html);
        self::assertStringContainsString('table-header-group', $html);

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'xlsx',
            'ids' => [$first->getId(), $second->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertIsString($path);
        file_put_contents($path, (string) $client->getResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $strings = $zip->getFromName('xl/sharedStrings.xml');
        $drawing = $zip->getFromName('xl/drawings/drawing1.xml');
        $zip->close();
        unlink($path);
        self::assertIsString($sheet);
        self::assertIsString($strings);
        self::assertIsString($drawing);
        self::assertStringContainsString('Cerâmica de coração', $strings);
        self::assertStringContainsString('Banco', $strings);
        self::assertStringContainsString('Categoria 1', $strings);
        self::assertStringContainsString('Categoria 2', $strings);
        self::assertStringContainsString('2 abanilhos', $strings);
        self::assertStringContainsString('M-CAT1-001', $strings);
        self::assertStringContainsString('1250.5', $sheet);
        self::assertStringContainsString('SUM(F2:F3)', $sheet);
        self::assertStringContainsString('SUM(G2:G3)', $sheet);
        self::assertStringContainsString('1625.65', $sheet);

        $client->request('POST', '/admin/records/export', [
            '_token' => $token,
            'format' => 'pdf',
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
        $all = static::getContainer()->get(SellerTable::class)->catalogueHtml([$second, $first]);
        self::assertStringContainsString('2 abanilhos', $all);
        self::assertStringContainsString('Cerâmica de coração', $all);
        self::assertStringContainsString('Banco', $all);
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
        self::assertSelectorNotExists('.ai-badge');

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Peça em registro', $html);
        self::assertStringNotContainsString('180,00', $html);
        self::assertStringNotContainsString('R$', $html);
        self::assertStringContainsString('<img', $html);

        $client->request('GET', '/admin/records');
        self::assertSelectorTextContains('body', 'Gerar novamente');
        $list = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('>R$</span>', $list);
        self::assertStringContainsString('value="180,00"', $list);
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
        ])), 'test-key', 'gemini-3.8-flash', 'gemini-3.5-flash-lite'));
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'generate' => '1',
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

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'generate' => '1',
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

    public function testPhotoUploadAndAutosaveDoNotQueueAndTheButtonDoes(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $jobs = static::getContainer()->get(\App\Repository\ProfileJobRepository::class);
        self::assertInstanceOf(\App\Repository\ProfileJobRepository::class, $jobs);

        $client->request('POST', '/admin/records/images', ['_token' => $token], ['image' => $this->jpeg()]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $jobs->recent());

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
        self::assertSame([], $jobs->recent());

        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '');
        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'generate' => '1',
            'title' => '',
            'size' => 'G',
            'category' => '2',
            'description' => '',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $jobs->recent());
    }

    public function testSizeAndCategoryStayEditableAndAreNotFilledByAi(): void
    {
        $client = $this->ownerClient();
        $client->disableReboot();
        $client->getContainer()->set(GeminiProfileGenerator::class, new GeminiProfileGenerator(new MockHttpClient(fn () => $this->gemini([
            'title' => 'Nome da IA',
            'description' => 'Texto da IA.',
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito à mão.',
            'size' => 'P',
            'symbolism' => '',
        ])), 'test-key', 'gemini-3.8-flash', 'gemini-3.5-flash-lite'));
        $client->request('GET', '/admin/records/new');
        self::assertSelectorExists('input[name="size"]:not([disabled])');
        self::assertSelectorExists('input[name="category"]:not([disabled])');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'generate' => '1',
            'title' => 'Nome dela',
            'size' => 'G',
            'category' => '3',
            'description' => 'Texto dela.',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $edit = $client->request('GET', '/admin/records/'.$payload['id'].'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('G', $edit->filter('input[name="size"][checked]')->attr('value'));
        self::assertSame('3', $edit->filter('input[name="category"][checked]')->attr('value'));
        self::assertSelectorExists('input[name="size"]:not([disabled])');
        self::assertSelectorExists('fieldset:not([disabled]) input[name="category"]');

        $jobs = static::getContainer()->get(\App\Repository\ProfileJobRepository::class);
        self::assertInstanceOf(\App\Repository\ProfileJobRepository::class, $jobs);
        $saved = static::getContainer()->get(\App\Repository\ArtefactRepository::class);
        self::assertInstanceOf(\App\Repository\ArtefactRepository::class, $saved);
        $current = $saved->find($payload['id']);
        self::assertInstanceOf(\App\Entity\Artefact::class, $current);
        self::assertSame('G', $current->getSize());
        self::assertSame(3, $current->getCategory());
        $before = count($jobs->recent());
        $client->setServerParameter('HTTP_ACCEPT', '');
        $client->request('POST', '/admin/records/'.$payload['id'].'/edit', [
            '_token' => $token,
            'title' => 'Nome dela',
            'size' => 'P',
            'category' => '1',
            'description' => 'Texto dela.',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'images' => $current->getImages(),
            'record_date' => '2026-11-18',
        ]);
        self::assertResponseRedirects('/admin/records/saved');
        self::assertCount($before, $jobs->recent());
        $artefact = $saved->find($payload['id']);
        self::assertInstanceOf(\App\Entity\Artefact::class, $artefact);
        self::assertSame('P', $artefact->getSize());
        self::assertSame(1, $artefact->getCategory());
        self::assertSame('Nome dela', $artefact->getTitle());
    }

    public function testSavedAudioIsConfirmedByReadingTheFileBack(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        self::assertSelectorTextContains('[data-profile-assist-target="savedAudioLabel"]', 'Sem áudio');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Com áudio',
            'size' => 'M',
            'category' => '1',
            'description' => 'Texto.',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'audio_ms' => '42000',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
            'audio' => $this->wav(),
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['audio']['stored']);
        self::assertMatchesRegularExpression('/Áudio gravado · 0:42 · \d{2}\/\d{2} \d{2}:\d{2}/', (string) $payload['audio']['label']);
        self::assertNull($payload['job']);
        self::assertSame('audio/wav', $payload['audio']['type']);

        $client->request('GET', (string) $payload['audio']['url'], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-type', 'audio/wav');
        self::assertStringStartsWith('bytes 0-3/', (string) $client->getResponse()->headers->get('content-range'));
        $client->request('GET', (string) $payload['editUrl']);
        self::assertSelectorExists('audio[preload="metadata"] source[type="audio/wav"]');
        $client->request('GET', '/admin/records');
        self::assertSelectorTextContains('.audio-badge', 'Tem áudio');

        $saved = static::getContainer()->get(\App\Repository\ArtefactRepository::class);
        self::assertInstanceOf(\App\Repository\ArtefactRepository::class, $saved);
        $artefact = $saved->find($payload['id']);
        self::assertInstanceOf(\App\Entity\Artefact::class, $artefact);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->request('POST', (string) $payload['editUrl'], [
            '_token' => $token,
            'title' => 'Com áudio',
            'size' => 'M',
            'category' => '1',
            'description' => 'Texto.',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'images' => $artefact->getImages(),
            'remove_audio' => '1',
            'record_date' => '2026-11-18',
        ]);
        self::assertResponseIsSuccessful();
        $removed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($removed);
        self::assertFalse($removed['audio']['stored']);
        self::assertSame('Sem áudio', $removed['audio']['label']);
    }

    public function testSafariAudioIsServedAsMp4WithARange(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Safari',
            'size' => 'M',
            'category' => '1',
            'description' => 'Texto.',
            'materials' => [['name' => 'Pedra', 'note' => 'Verde.']],
            'audio_ms' => '39000',
            'record_date' => '2026-11-18',
        ], [
            'uploads' => [$this->jpeg()],
            'audio' => $this->mp4(),
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('audio/mp4', $payload['audio']['type']);
        $saved = static::getContainer()->get(\App\Repository\ArtefactRepository::class);
        self::assertInstanceOf(\App\Repository\ArtefactRepository::class, $saved);
        $artefact = $saved->find($payload['id']);
        self::assertInstanceOf(\App\Entity\Artefact::class, $artefact);
        self::assertStringEndsWith('.m4a', (string) $artefact->getAudioPath());

        $client->request('GET', (string) $payload['audio']['url'], [], [], ['HTTP_RANGE' => 'bytes=0-7']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-type', 'audio/mp4');
        self::assertStringStartsWith('bytes 0-7/', (string) $client->getResponse()->headers->get('content-range'));
        $client->request('GET', (string) $payload['editUrl']);
        self::assertSelectorExists('audio[preload="metadata"] source[type="audio/mp4"]');
    }

    public function testInlinePatchSavesOnlyThatFieldAndDoesNotQueueAi(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $artefact = $registrar->create($this->data('Cocar', 'Texto já escrito.', 125050));
        $client->request('GET', '/admin/records');
        $token = $client->getCrawler()->filter('[data-inline-cell-token-value]')->attr('data-inline-cell-token-value');
        self::assertIsString($token);
        self::assertSelectorExists('select[id^="size-"] option[value="G"]');
        self::assertSelectorExists('select[id^="category-"] option[value="3"]');
        self::assertSelectorTextContains('select[id^="category-"] option[value="1"]', 'Categoria 1');
        self::assertSelectorExists('input[id^="title-"][data-action*="focusout->inline-cell#commit"]');
        self::assertSelectorExists('input[id^="price-"][inputmode="decimal"][placeholder="0,00"]');
        self::assertSelectorExists('.row-price-prefix');
        self::assertSame(10, $client->getCrawler()->filter('.record-head > span')->count());
        self::assertSelectorTextContains('.record-head', 'Sugerido');
        self::assertSelectorTextContains('.record-head', 'Preço');
        self::assertSelectorTextContains('.record-head', 'Status');
        self::assertSelectorTextContains('.record-head', 'Ações');
        $publicToken = $artefact->getPublicToken();

        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'price',
            'value' => 'R$ 99,90',
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame('99,90', $payload['value']);

        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'size',
            'value' => 'G',
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'category',
            'value' => '3',
        ]);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $stored = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $stored);
        self::assertSame(9990, $stored->getSalePriceCents());
        self::assertSame('G', $stored->getSize());
        self::assertSame(3, $stored->getCategory());
        self::assertSame('Cocar', $stored->getTitle());
        self::assertSame('Texto já escrito.', $stored->getDescription());
        self::assertSame('M-CAT1-001', $stored->getCode());
        self::assertSame($publicToken, $stored->getPublicToken());
        self::assertSame([], static::getContainer()->get(\App\Repository\ProfileJobRepository::class)->findAll());

        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'title',
            'value' => '  Sol de japu  ',
        ]);
        self::assertResponseIsSuccessful();
        $renamed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($renamed);
        self::assertSame('Sol de japu', $renamed['value']);
        $em->clear();
        $named = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $named);
        self::assertSame('Sol de japu', $named->getTitle());
        self::assertTrue($named->isManual('title'));
        self::assertSame($publicToken, $named->getPublicToken());
        self::assertSame('M-CAT1-001', $named->getCode());
        self::assertSame('Texto já escrito.', $named->getDescription());
        self::assertSame(9990, $named->getSalePriceCents());
        self::assertSame('G', $named->getSize());
        self::assertSame(3, $named->getCategory());
        $named->fillFromProfile(new \App\Artefact\GeneratedProfile(
            'Nome da IA',
            'Texto da IA.',
            '30 cm',
            [['name' => 'Linho', 'note' => 'Cru.']],
            ['Não molhar.'],
            'Feito devagar.',
            'P',
            'Outro símbolo.',
        ));
        $em->flush();
        $em->clear();
        $kept = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $kept);
        self::assertSame('Sol de japu', $kept->getTitle());
        self::assertSame($publicToken, $kept->getPublicToken());
        self::assertSame('Texto já escrito.', $kept->getDescription());
        self::assertSame([], static::getContainer()->get(\App\Repository\ProfileJobRepository::class)->findAll());

        $open = $registrar->create(\App\Artefact\ArtefactData::fromSubmitted('', 'M', 1, '', '', [['name' => 'Barro', 'note' => 'Queimado.']], 'Guardar seco.', 'Feito à mão com calma.', [self::IMAGE], '2026-10-18'));
        self::assertFalse($open->isManual('title'));
        $openToken = $open->getPublicToken();
        $client->request('POST', '/admin/records/'.$open->getId().'/field', [
            '_token' => $token,
            'field' => 'title',
            'value' => 'Nome dela',
        ]);
        self::assertResponseIsSuccessful();
        $em->clear();
        $hers = $em->find(\App\Entity\Artefact::class, $open->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $hers);
        self::assertSame('Nome dela', $hers->getTitle());
        self::assertTrue($hers->isManual('title'));
        self::assertSame($openToken, $hers->getPublicToken());
        $hers->fillFromProfile(new \App\Artefact\GeneratedProfile('Nome da IA', 'Texto da IA.', '', [], [], '', 'M'));
        $em->flush();
        $em->clear();
        $stillHers = $em->find(\App\Entity\Artefact::class, $open->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $stillHers);
        self::assertSame('Nome dela', $stillHers->getTitle());
        self::assertSame($openToken, $stillHers->getPublicToken());
        self::assertSame([], static::getContainer()->get(\App\Repository\ProfileJobRepository::class)->findAll());

        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'size',
            'value' => 'X',
        ]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'category',
            'value' => '9',
        ]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'price',
            'value' => 'abc',
        ]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'title',
            'value' => '   ',
        ]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/records/'.$artefact->getId().'/field', [
            '_token' => $token,
            'field' => 'notes',
            'value' => 'Outro',
        ]);
        self::assertResponseStatusCodeSame(422);
        $em->clear();
        $unchanged = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $unchanged);
        self::assertSame('G', $unchanged->getSize());
        self::assertSame(3, $unchanged->getCategory());
        self::assertSame(9990, $unchanged->getSalePriceCents());
        self::assertSame('Sol de japu', $unchanged->getTitle());
        self::assertSame($publicToken, $unchanged->getPublicToken());
        self::assertTrue($unchanged->isManual('title'));
    }

    public function testInlinePostSavesPriceSizeAndCategory(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $artefact = $registrar->create($this->data('Cocar', 'Texto já escrito.', 125050));
        $client->request('GET', '/admin/records');
        $token = $client->getCrawler()->filter('[data-inline-cell-token-value]')->attr('data-inline-cell-token-value');
        self::assertIsString($token);
        $url = '/admin/records/'.$artefact->getId().'/field';
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/assets/controllers/inline_cell_controller.js');
        self::assertStringContainsString("method: 'POST'", $script);
        self::assertStringNotContainsString("method: 'PATCH'", $script);

        $client->request('POST', $url, ['_token' => $token, 'field' => 'price', 'value' => '12,50']);
        self::assertResponseIsSuccessful();
        $priced = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($priced);
        self::assertSame('12,50', $priced['value']);

        $client->request('POST', $url, ['_token' => $token, 'field' => 'price', 'value' => '']);
        self::assertResponseIsSuccessful();
        $cleared = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($cleared);
        self::assertSame('', $cleared['value']);

        $client->request('POST', $url, ['_token' => $token, 'field' => 'size', 'value' => 'M']);
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, ['_token' => $token, 'field' => 'category', 'value' => '2']);
        self::assertResponseIsSuccessful();
        $category = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($category);
        self::assertSame('2', $category['value']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $stored = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $stored);
        self::assertNull($stored->getSalePriceCents());
        self::assertSame('M', $stored->getSize());
        self::assertSame(2, $stored->getCategory());
        self::assertSame('Cocar', $stored->getTitle());
        self::assertSame([], static::getContainer()->get(\App\Repository\ProfileJobRepository::class)->findAll());

        $log = dirname(__DIR__, 2).'/var/logs/inline.log';
        if (is_file($log)) {
            unlink($log);
        }
        $client->request('POST', $url, ['_token' => 'nope', 'field' => 'size', 'value' => 'M']);
        self::assertResponseStatusCodeSame(422);
        $denied = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($denied);
        self::assertStringContainsString('Atualize a página', (string) $denied['error']);
        self::assertStringContainsString('csrf', (string) file_get_contents($log));
        self::assertSame('M', $em->find(\App\Entity\Artefact::class, $artefact->getId())?->getSize());
    }

    public function testSuggestedPriceFollowsTheSellingPriceUntilSheEditsIt(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $artefact = $registrar->create($this->data('Cocar', 'Texto já escrito.', 10000));
        self::assertSame(13000, $artefact->getSuggestedPriceCents());
        self::assertFalse($artefact->isSuggestedPriceManual());
        $client->request('GET', '/admin/records');
        $token = $client->getCrawler()->filter('[data-inline-cell-token-value]')->attr('data-inline-cell-token-value');
        self::assertIsString($token);
        $url = '/admin/records/'.$artefact->getId().'/field';

        $client->request('POST', $url, ['_token' => $token, 'field' => 'suggested', 'value' => '100,00']);
        self::assertResponseIsSuccessful();
        $low = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($low);
        self::assertSame('100,00', $low['value']);
        self::assertSame('Abaixo de 30% sobre o preço.', $low['warning']);

        $client->request('POST', $url, ['_token' => $token, 'field' => 'price', 'value' => '200,00']);
        self::assertResponseIsSuccessful();
        $raised = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($raised);
        self::assertSame('100,00', $raised['suggested']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $stored = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $stored);
        self::assertSame(20000, $stored->getSalePriceCents());
        self::assertSame(10000, $stored->getSuggestedPriceCents());
        self::assertTrue($stored->isSuggestedPriceManual());

        $client->request('POST', $url, ['_token' => $token, 'field' => 'suggested', 'value' => '']);
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, ['_token' => $token, 'field' => 'price', 'value' => '50,00']);
        self::assertResponseIsSuccessful();
        $em->clear();
        $again = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $again);
        self::assertSame(5000, $again->getSalePriceCents());
        self::assertSame(6500, $again->getSuggestedPriceCents());
        self::assertFalse($again->isSuggestedPriceManual());
    }

    public function testEditorAutosaveStoresTitleAndPriceWithoutQueueingAi(): void
    {
        $client = $this->ownerClient();
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);
        $artefact = $registrar->create($this->data('Cocar', 'Texto já escrito.'));
        $client->request('GET', '/admin/records/'.$artefact->getId().'/edit');
        self::assertSelectorExists('input[name="sale_price"][inputmode="decimal"]');
        self::assertSelectorExists('input[name="_token"]');
        self::assertSelectorExists('form[data-action*="focusout->artefact-editor#save"]');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->setServerParameter('HTTP_ACCEPT', 'application/json');
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->request('POST', '/admin/records/'.$artefact->getId().'/edit', [
            '_token' => $token,
            'title' => 'Nome no telefone',
            'size' => 'M',
            'category' => '1',
            'description' => 'Texto já escrito.',
            'dimensions' => '20 cm',
            'materials' => [['name' => 'Barro', 'note' => 'Queimado.']],
            'care' => 'Guardar seco.',
            'handmade' => 'Feito à mão com calma.',
            'images' => $artefact->getImages(),
            'record_date' => '2026-10-18',
            'sale_price' => '80,00',
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertNull($payload['job'] ?? null);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $stored = $em->find(\App\Entity\Artefact::class, $artefact->getId());
        self::assertInstanceOf(\App\Entity\Artefact::class, $stored);
        self::assertSame('Nome no telefone', $stored->getTitle());
        self::assertSame(8000, $stored->getSalePriceCents());
        self::assertSame([], static::getContainer()->get(\App\Repository\ProfileJobRepository::class)->findAll());
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/assets/controllers/artefact_editor_controller.js');
        self::assertStringContainsString('sale_price', $script);
        self::assertStringContainsString('element.value', $script);
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

    private function mp4(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'm4a');
        self::assertIsString($path);
        file_put_contents($path, hex2bin('00000018667479706d703432000000006d703432').str_repeat("\0", 16));

        return new UploadedFile($path, 'gravacao.mp4', 'audio/mp4', null, true);
    }

    private function wav(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wav');
        self::assertIsString($path);
        file_put_contents($path, 'RIFF'.pack('V', 36).'WAVEfmt '.pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', 0));

        return new UploadedFile($path, 'descricao.wav', 'audio/wav', null, true);
    }
}
