<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Artefact\ArtefactRules;
use App\Tests\SchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ArchiveFlowTest extends WebTestCase
{
    public function testBackupDownloadIsReservedForTheOwner(): void
    {
        $client = $this->browser();
        $client->request('GET', '/admin/records/backup');
        self::assertResponseRedirects('/');
    }

    public function testTheOwnerCanDownloadABackupAndSeeWhenItWasTaken(): void
    {
        $marker = dirname(__DIR__, 2).'/var/last-download.txt';
        if (is_file($marker)) {
            unlink($marker);
        }
        $owner = $this->ownerClient();
        $owner->request('GET', '/admin/records');
        self::assertSelectorTextContains('.backup-status', 'Último backup baixado: nenhum');
        $owner->request('GET', '/admin/records/backup');
        self::assertResponseIsSuccessful();
        $disposition = (string) $owner->getResponse()->headers->get('content-disposition');
        self::assertMatchesRegularExpression('/origens-backup-\d{8}-\d{4}\.zip/', $disposition);
        $owner->request('GET', '/admin/records');
        self::assertSelectorTextContains('.backup-status', 'Último backup baixado:');
        self::assertStringNotContainsString('Último backup baixado: nenhum', (string) $owner->getResponse()->getContent());
    }

    public function testHomeIsTheOwnerLogin(): void
    {
        $client = $this->browser();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorTextContains('h1', 'ORIGENS');
        self::assertSelectorNotExists('h1.piece-title');

        $client->request('GET', '/p/org-001');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Peça não encontrada');
    }

    public function testUnknownAndShortCodesAreNotPublicPages(): void
    {
        $client = $this->browser();

        $client->request('GET', '/p/abc123');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Peça não encontrada');

        $client->request('GET', '/p/M-CAT1-001');
        self::assertResponseStatusCodeSame(404);
    }

    public function testArchiveRequiresTheOwner(): void
    {
        $client = $this->browser();
        $client->request('GET', '/admin/records');

        self::assertResponseRedirects('/');

        $client->request('GET', '/auth');
        self::assertResponseRedirects('/');

        $client->request('GET', '/admin');
        self::assertResponseRedirects('/');
    }

    public function testOwnerCanRegisterAPieceWithoutPublishingItsShortCode(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Cocar',
            'size' => 'M',
            'category' => '1',
            'description' => 'Uma peça de teste.',
            'dimensions' => '20 cm',
            'materials' => [['name' => 'Pedra', 'note' => 'Labradorita informada.']],
            'care' => "Guardar seco.",
            'handmade' => 'Feito à mão com calma.',
            'record_date' => '2026-10-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();
        $code = trim($client->getCrawler()->filter('.saved-code')->text());
        $url = trim($client->getCrawler()->filter('.saved-url')->text());
        $path = parse_url($url, PHP_URL_PATH);

        self::assertTrue(ArtefactRules::isValidCode($code));
        self::assertIsString($path);
        self::assertMatchesRegularExpression('#^/p/[a-f0-9]{32}$#', $path);

        $client->request('GET', '/p/'.$code);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cocar');
        self::assertSelectorTextContains('body', $code);
        self::assertSelectorTextContains('body', 'Pedra');

        $client->request('GET', '/admin/records');
        self::assertSame($path, $client->getCrawler()->filter('a[aria-label="Abrir '.$code.'"]')->attr('href'));
        $edit = $client->getCrawler()->filter('a[aria-label="Editar '.$code.'"]')->attr('href');
        self::assertIsString($edit);

        $crawler = $client->request('GET', $edit);
        $form = $crawler->filter('form.editor-panel')->form([
            'title' => 'Cocar revisado',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();

        self::assertSame($code, trim($client->getCrawler()->filter('.saved-code')->text()));
        $client->request('GET', $path);
        self::assertSelectorTextContains('h1', 'Cocar revisado');

        $crawler = $client->request('GET', $edit);
        $form = $crawler->filter('form.editor-panel')->form(['title' => 'Cocar no campo']);
        $client->setServerParameter('HTTP_X_ORIGENS_AUTOSAVE', '1');
        $client->submit($form);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame($code, $payload['code']);

        $client->request('GET', $path);
        self::assertSelectorTextContains('h1', 'Cocar no campo');

        $client->request('GET', '/admin/records');
        $client->submitForm('Excluir '.$code);
        self::assertResponseRedirects('/admin/records');
        $client->followRedirect();
        self::assertSelectorNotExists('.code');

        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPasswordStaysOnTheLoginPage(): void
    {
        $client = $this->browser();
        $client->request('GET', '/');
        $client->submitForm('Entrar', ['_password' => 'errada']);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'Não foi possível entrar');
    }

    public function testPublicPageDoesNotContainTheSalePrice(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Cocar',
            'size' => 'M',
            'category' => '1',
            'description' => 'Uma peça de teste.',
            'dimensions' => '20 cm',
            'materials' => [['name' => 'Pedra', 'note' => 'Labradorita informada.']],
            'care' => 'Guardar seco.',
            'handmade' => 'Feito à mão com calma.',
            'record_date' => '2026-10-18',
            'sale_price' => 'R$ 987,65',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();
        $path = parse_url(trim($client->getCrawler()->filter('.saved-url')->text()), PHP_URL_PATH);
        self::assertIsString($path);

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('content-type'));
        $html = (string) $client->getResponse()->getContent();
        $lower = mb_strtolower($html);
        self::assertStringContainsString('Cocar', $html);
        self::assertStringNotContainsString('987,65', $html);
        self::assertStringNotContainsString('987.65', $html);
        self::assertStringNotContainsString('98765', $html);
        self::assertStringNotContainsString('R$', $html);
        self::assertStringNotContainsString('preço', $lower);
        self::assertStringNotContainsString('venda', $lower);
        self::assertStringNotContainsString('sale_price', $lower);
        self::assertStringNotContainsString('salepricecents', $lower);
        self::assertStringNotContainsString('class="nfc"', $html);
        self::assertStringNotContainsString('nfc-url', $html);
        self::assertStringNotContainsString('class="symbolism"', $html);

        $client->request('GET', '/admin/records');
        self::assertStringContainsString('R$ 987,65', (string) $client->getResponse()->getContent());
        $edit = $client->getCrawler()->filter('a[aria-label="Editar M-CAT1-001"]')->attr('href');
        self::assertIsString($edit);
        $client->request('GET', $edit);
        self::assertSame('987,65', $client->getCrawler()->filter('input[name="sale_price"]')->attr('value'));
    }

    public function testPublicPageShowsTheSymbolicMessageOnlyWhenItIsFilled(): void
    {
        $client = $this->ownerClient();
        $client->request('GET', '/admin/records/new');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/records/new', [
            '_token' => $token,
            'title' => 'Garoa',
            'size' => 'P',
            'category' => '1',
            'description' => 'Uma peça de teste.',
            'symbolism' => 'Chuva fina e pena clara firmam quem está de passagem.',
            'dimensions' => '12 cm',
            'materials' => [['name' => 'Pena', 'note' => 'Clara.']],
            'care' => 'Guardar seco.',
            'handmade' => 'Feito à mão com calma.',
            'record_date' => '2026-10-18',
        ], [
            'uploads' => [$this->jpeg()],
        ]);

        self::assertResponseRedirects('/admin/records/saved');
        $client->followRedirect();
        $path = parse_url(trim($client->getCrawler()->filter('.saved-url')->text()), PHP_URL_PATH);
        self::assertIsString($path);

        $client->request('GET', $path);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('class="symbolism"', $html);
        self::assertStringContainsString('Chuva fina e pena clara firmam quem está de passagem.', $html);
        self::assertStringNotContainsString('class="nfc"', $html);
        self::assertStringNotContainsString('987', $html);
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

    private function jpeg(): UploadedFile
    {
        $image = imagecreatetruecolor(8, 10);
        imagejpeg($image, $path = tempnam(sys_get_temp_dir(), 'org'));
        unset($image);

        return new UploadedFile($path, 'pena.jpg', 'image/jpeg', null, true);
    }
}
