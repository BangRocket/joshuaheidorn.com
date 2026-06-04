<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ResumeRepository;
use Dompdf\Dompdf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PdfController
{
    public function __construct(private Twig $twig, private ResumeRepository $resume)
    {
    }

    public function resume(Request $request, Response $response): Response
    {
        $meta = $this->resume->meta();
        $html = $this->twig->fetch('resume_pdf.twig', [
            'resume' => $meta,
            'experience' => $this->resume->experience(),
            'education' => $this->resume->education(),
            'skills' => $this->resume->skills(),
        ]);

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        $name = preg_replace('/[^a-z0-9]+/i', '-', $meta['name'] ?? 'resume');
        $response->getBody()->write($dompdf->output());

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . strtolower(trim((string) $name, '-')) . '.pdf"');
    }
}
