<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\SettingsRepository;
use App\Repositories\SettingsWriteRepository;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController
{
    private SettingsRepository $read;
    private SettingsWriteRepository $write;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->read = new SettingsRepository($pdo);
        $this->write = new SettingsWriteRepository($pdo);
    }

    public function edit(Request $request, Response $response): Response
    {
        $settings = $this->read->all();
        return $this->twig->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'menuJson' => json_encode($this->read->menu(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->write->setSetting('site_title', (string) ($data['site_title'] ?? ''));
        $this->write->setSetting('site_tagline', (string) ($data['site_tagline'] ?? ''));

        $menu = json_decode((string) ($data['menu'] ?? ''), true);
        if (!is_array($menu)) {
            return $response->withHeader('Location', '/admin/settings?error=json')->withStatus(302);
        }
        $this->write->saveMenu($menu);
        return $response->withHeader('Location', '/admin/settings?saved=1')->withStatus(302);
    }
}
