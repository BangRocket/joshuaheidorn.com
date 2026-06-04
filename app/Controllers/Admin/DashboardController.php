<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(private Twig $twig, private PDO $pdo)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $counts = [];
        foreach (['posts', 'projects', 'pages', 'media'] as $t) {
            $counts[$t] = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }
        return $this->twig->render($response, 'admin/dashboard.twig', [
            'user' => $_SESSION['uname'] ?? 'Admin',
            'counts' => $counts,
        ]);
    }
}
