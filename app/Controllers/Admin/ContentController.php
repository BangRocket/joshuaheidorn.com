<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\AdminContentRepository;
use App\Repositories\MediaRepository;
use App\Repositories\TaxonomyWriteRepository;
use App\Support\Csrf;
use App\Support\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ContentController
{
    /** type => config */
    private const TYPES = [
        'posts' => ['singular' => 'Post', 'contentType' => 'post', 'taxonomies' => ['tag', 'category'], 'hasExcerpt' => true, 'hasSummary' => false, 'hasFeatured' => false, 'hasLinks' => false, 'hasImage' => true, 'urlPrefix' => '/posts/'],
        'projects' => ['singular' => 'Project', 'contentType' => 'project', 'taxonomies' => ['tag'], 'hasExcerpt' => false, 'hasSummary' => true, 'hasFeatured' => true, 'hasLinks' => true, 'hasImage' => true, 'urlPrefix' => '/projects/'],
        'pages' => ['singular' => 'Page', 'contentType' => 'page', 'taxonomies' => [], 'hasExcerpt' => false, 'hasSummary' => false, 'hasFeatured' => false, 'hasLinks' => false, 'hasImage' => false, 'urlPrefix' => '/pages/'],
    ];

    private AdminContentRepository $content;
    private TaxonomyWriteRepository $tax;
    private MediaRepository $media;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->content = new AdminContentRepository($pdo);
        $this->tax = new TaxonomyWriteRepository($pdo);
        $this->media = new MediaRepository($pdo);
    }

    private function typeFromPath(Request $request): string
    {
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        return $segments[1]; // /admin/{type}/...
    }

    public function index(Request $request, Response $response): Response
    {
        $type = $this->typeFromPath($request);
        return $this->twig->render($response, 'admin/content_list.twig', [
            'type' => $type,
            'cfg' => self::TYPES[$type],
            'items' => $this->content->all($type),
            'user' => $_SESSION['uname'] ?? 'Admin',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->form($response, $this->typeFromPath($request), null);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $item = $this->content->find($type, (int) $args['id']);
        if (!$item) {
            return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
        }
        return $this->form($response, $type, $item);
    }

    private function form(Response $response, string $type, ?array $item): Response
    {
        $cfg = self::TYPES[$type];
        $tags = [];
        foreach ($cfg['taxonomies'] as $tx) {
            $tags[$tx] = $item ? implode(', ', $this->tax->labels($tx, $cfg['contentType'], (int) $item['id'])) : '';
        }
        return $this->twig->render($response, 'admin/content_form.twig', [
            'type' => $type,
            'cfg' => $cfg,
            'item' => $item,
            'tagValues' => $tags,
            'media' => $this->media->all(),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $type = $this->typeFromPath($request);
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $id = $this->content->create($type, $this->fields($type, $data));
        $this->syncTax($type, $id, $data);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->content->update($type, $id, $this->fields($type, $data));
        $this->syncTax($type, $id, $data);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->content->delete($type, (int) $args['id']);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    /** Build the column => value map from the submitted form for this type. */
    private function fields(string $type, array $data): array
    {
        $cfg = self::TYPES[$type];
        $title = trim((string) ($data['title'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? '')) ?: Slug::make($title);
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $publishedAt = trim((string) ($data['published_at'] ?? ''));

        $fields = [
            'title' => $title,
            'slug' => $slug,
            'body_md' => (string) ($data['body_md'] ?? ''),
            'status' => $status,
            'published_at' => $publishedAt !== '' ? $publishedAt : ($status === 'published' ? date('Y-m-d H:i:s') : null),
        ];
        if ($cfg['hasExcerpt']) {
            $fields['excerpt'] = (string) ($data['excerpt'] ?? '');
        }
        if ($cfg['hasSummary']) {
            $fields['summary'] = (string) ($data['summary'] ?? '');
        }
        if ($cfg['hasLinks']) {
            $fields['source_url'] = trim((string) ($data['source_url'] ?? '')) ?: null;
            $fields['external_url'] = trim((string) ($data['external_url'] ?? '')) ?: null;
        }
        if ($cfg['hasFeatured']) {
            $fields['featured'] = !empty($data['featured']) ? 1 : 0;
        }
        if ($cfg['hasImage']) {
            $fields['featured_image_id'] = ($data['featured_image_id'] ?? '') !== '' ? (int) $data['featured_image_id'] : null;
        }
        return $fields;
    }

    private function syncTax(string $type, int $id, array $data): void
    {
        $cfg = self::TYPES[$type];
        foreach ($cfg['taxonomies'] as $tx) {
            $labels = array_filter(array_map('trim', explode(',', (string) ($data['tax_' . $tx] ?? ''))));
            $this->tax->sync($tx, $cfg['contentType'], $id, $labels);
        }
    }
}
