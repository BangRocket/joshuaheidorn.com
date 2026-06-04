<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\ResumeRepository;
use App\Repositories\ResumeWriteRepository;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ResumeController
{
    private ResumeRepository $read;
    private ResumeWriteRepository $write;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->read = new ResumeRepository($pdo);
        $this->write = new ResumeWriteRepository($pdo);
    }

    public function edit(Request $request, Response $response): Response
    {
        $meta = $this->read->meta();
        $resume = [
            'name' => $meta['name'], 'headline' => $meta['headline'] ?? '',
            'headlines' => $meta['headlines'], 'summary' => $meta['summary'] ?? '',
            'contact' => $meta['contact'],
            'experience' => $this->read->experience(),
            'education' => $this->read->education(),
        ];
        return $this->twig->render($response, 'admin/resume.twig', [
            'resumeJson' => json_encode($resume, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'skillsJson' => json_encode($this->read->skills(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'error' => $request->getQueryParams()['error'] ?? null,
            'saved' => $request->getQueryParams()['saved'] ?? null,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $resume = json_decode((string) ($data['resume'] ?? ''), true);
        $skills = json_decode((string) ($data['skills'] ?? ''), true);
        if (!is_array($resume) || !is_array($skills)) {
            return $response->withHeader('Location', '/admin/resume?error=json')->withStatus(302);
        }
        $this->write->saveResume($resume);
        $this->write->saveSkills($skills);
        return $response->withHeader('Location', '/admin/resume?saved=1')->withStatus(302);
    }
}
