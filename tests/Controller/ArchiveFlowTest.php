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
