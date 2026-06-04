<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\MediaRepository;
use App\Support\Csrf;
use App\Support\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class MediaController
{
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    private MediaRepository $media;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->media = new MediaRepository($pdo);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/media.twig', [
            'items' => $this->media->all(),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function upload(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withHeader('Location', '/admin/media?error=upload')->withStatus(302);
        }
        $mime = $file->getClientMediaType();
        if (!isset(self::ALLOWED[$mime])) {
            return $response->withHeader('Location', '/admin/media?error=type')->withStatus(302);
        }

        $ext = self::ALLOWED[$mime];
        $base = Slug::make(pathinfo($file->getClientFilename() ?? 'image', PATHINFO_FILENAME)) ?: 'image';
        $name = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest = dirname(__DIR__, 3) . '/public/uploads/' . $name;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        $file->moveTo($dest);

        $w = $h = null;
        $info = @getimagesize($dest);
        if ($info !== false) {
            [$w, $h] = $info;
        }
        $this->media->create($name, '/uploads/' . $name, $w, $h, $mime);

        return $response->withHeader('Location', '/admin/media')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $item = $this->media->find((int) $args['id']);
        if ($item) {
            $path = dirname(__DIR__, 3) . '/public' . $item['path'];
            if (str_starts_with((string) $item['path'], '/uploads/') && is_file($path)) {
                @unlink($path);
            }
            $this->media->delete((int) $item['id']);
        }
        return $response->withHeader('Location', '/admin/media')->withStatus(302);
    }
}
