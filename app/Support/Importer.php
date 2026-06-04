<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Importer
{
    private const BASE_DATE = '2026-04-01 12:00:00';

    /** @var array<string,int> path => media id */
    private array $mediaByPath = [];
    /** @var array<string,int> ulid => media id */
    private array $mediaByUlid = [];
    /** @var array<string,int> "taxonomy|slug" => term id */
    private array $terms = [];

    /** @param array{base:string,uploads_src:string,uploads_dest:string} $paths */
    public function __construct(private PDO $pdo, private array $paths)
    {
    }

    public function run(): void
    {
        $this->truncate();
        $seed = $this->json($this->paths['base'] . '/seed/seed.json');

        $this->importSettings($seed['settings'] ?? []);
        $this->importMenu($seed['menus'] ?? []);
        $this->importTerms($seed['taxonomies'] ?? []);
        $this->importMedia();
        $this->importPosts($seed['content']['posts'] ?? []);
        $this->importPages($seed['content']['pages'] ?? []);
        $this->importProjects($this->json($this->paths['base'] . '/src/data/projects.json')['projects'] ?? []);
        $this->importResume($this->json($this->paths['base'] . '/src/data/resume.json'));
        $this->importSkills($this->json($this->paths['base'] . '/src/data/skills.json')['categories'] ?? []);
    }

    private function json(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$file}");
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function truncate(): void
    {
        $tables = [
            'term_relationships', 'skills', 'skill_categories', 'experience',
            'education', 'resume_meta', 'menu_items', 'settings',
            'posts', 'projects', 'pages', 'terms', 'media',
        ];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function importSettings(array $settings): void
    {
        $map = [
            'site_title' => $settings['title'] ?? 'joshuaheidorn.com',
            'site_tagline' => $settings['tagline'] ?? '',
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)'
        );
        foreach ($map as $k => $v) {
            $stmt->execute(['k' => $k, 'v' => $v]);
        }
    }

    private function importMenu(array $menus): void
    {
        $primary = null;
        foreach ($menus as $menu) {
            if (($menu['name'] ?? '') === 'primary') {
                $primary = $menu;
            }
        }
        if ($primary === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (label, url, target, sort) VALUES (:label, :url, :target, :sort)'
        );
        foreach (($primary['items'] ?? []) as $i => $item) {
            $stmt->execute([
                'label' => $item['label'] ?? '',
                'url' => $item['url'] ?? '#',
                'target' => $item['target'] ?? null,
                'sort' => $i,
            ]);
        }
    }

    private function importTerms(array $taxonomies): void
    {
        foreach ($taxonomies as $tax) {
            $name = $tax['name'] ?? '';
            foreach (($tax['terms'] ?? []) as $term) {
                $this->termId($name, $term['slug'], $term['label'] ?? $term['slug']);
            }
        }
    }

    private function termId(string $taxonomy, string $slug, ?string $label = null): int
    {
        $key = $taxonomy . '|' . $slug;
        if (isset($this->terms[$key])) {
            return $this->terms[$key];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO terms (taxonomy, slug, label) VALUES (:t, :s, :l)'
        );
        $stmt->execute(['t' => $taxonomy, 's' => $slug, 'l' => $label ?? ucfirst($slug)]);

        return $this->terms[$key] = (int) $this->pdo->lastInsertId();
    }

    private function importMedia(): void
    {
        $src = $this->paths['uploads_src'];
        $dest = $this->paths['uploads_dest'];
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (glob($src . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = basename($file);
            copy($file, $dest . '/' . $name);

            $width = $height = null;
            $mime = null;
            $info = @getimagesize($file);
            if ($info !== false) {
                [$width, $height] = $info;
                $mime = $info['mime'] ?? null;
            }

            $id = $this->insertMedia('/uploads/' . $name, $name, '', $width, $height, $mime);
            $this->mediaByUlid[pathinfo($name, PATHINFO_FILENAME)] = $id;
        }
    }

    private function insertMedia(string $path, string $filename, string $alt, ?int $w, ?int $h, ?string $mime): int
    {
        if (isset($this->mediaByPath[$path])) {
            return $this->mediaByPath[$path];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (filename, path, alt, width, height, mime)
             VALUES (:f, :p, :a, :w, :h, :m)'
        );
        $stmt->execute(['f' => $filename, 'p' => $path, 'a' => $alt, 'w' => $w, 'h' => $h, 'm' => $mime]);

        return $this->mediaByPath[$path] = (int) $this->pdo->lastInsertId();
    }

    private function mediaFromSeedImage(?array $featured): ?int
    {
        if ($featured === null || !isset($featured['$media'])) {
            return null;
        }
        $m = $featured['$media'];
        $url = $m['url'] ?? '';
        if ($url === '') {
            return null;
        }
        return $this->insertMedia($url, $m['filename'] ?? basename($url), $m['alt'] ?? '', null, null, null);
    }

    private function mediaFromProjectImage(?array $featured): ?int
    {
        if ($featured === null || empty($featured['src'])) {
            return null;
        }
        $src = $featured['src'];
        if (preg_match('#/media/file/([^/?]+)#', $src, $match)) {
            $name = $match[1];
            $ulid = pathinfo($name, PATHINFO_FILENAME);
            if (isset($this->mediaByUlid[$ulid])) {
                return $this->mediaByUlid[$ulid];
            }
            return $this->insertMedia('/uploads/' . $name, $name, $featured['alt'] ?? '', $featured['width'] ?? null, $featured['height'] ?? null, null);
        }
        return $this->insertMedia($src, basename($src), $featured['alt'] ?? '', $featured['width'] ?? null, $featured['height'] ?? null, null);
    }

    private function publishedAt(int $index): string
    {
        return date('Y-m-d H:i:s', strtotime(self::BASE_DATE . " -{$index} days"));
    }

    private function importPosts(array $posts): void
    {
        $pt = new PortableText();
        $stmt = $this->pdo->prepare(
            'INSERT INTO posts (slug, title, excerpt, body_md, featured_image_id, status, published_at)
             VALUES (:slug, :title, :excerpt, :body, :img, :status, :published)'
        );
        foreach ($posts as $i => $post) {
            $data = $post['data'] ?? [];
            $status = $post['status'] ?? 'published';
            $stmt->execute([
                'slug' => $post['slug'] ?? Slug::make($data['title'] ?? 'post'),
                'title' => $data['title'] ?? 'Untitled',
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $pt->toMarkdown($data['content'] ?? []),
                'img' => $this->mediaFromSeedImage($data['featured_image'] ?? null),
                'status' => $status,
                'published' => $status === 'published' ? $this->publishedAt($i) : null,
            ]);
            $postId = (int) $this->pdo->lastInsertId();
            $this->linkTaxonomies('post', $postId, $post['taxonomies'] ?? []);
        }
    }

    private function importPages(array $pages): void
    {
        $pt = new PortableText();
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (slug, title, body_md, status, published_at)
             VALUES (:slug, :title, :body, :status, :published)'
        );
        foreach ($pages as $i => $page) {
            $data = $page['data'] ?? [];
            $status = $page['status'] ?? 'published';
            $stmt->execute([
                'slug' => $page['slug'] ?? Slug::make($data['title'] ?? 'page'),
                'title' => $data['title'] ?? 'Untitled',
                'body' => $pt->toMarkdown($data['content'] ?? []),
                'status' => $status,
                'published' => $status === 'published' ? $this->publishedAt($i) : null,
            ]);
        }
    }

    private function importProjects(array $projects): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (slug, title, summary, body_md, source_url, external_url, featured, featured_image_id, status, published_at)
             VALUES (:slug, :title, :summary, :body, :source, :external, :featured, :img, :status, :published)'
        );
        foreach ($projects as $i => $project) {
            $body = '';
            foreach (($project['sections'] ?? []) as $section) {
                if (!empty($section['heading'])) {
                    $body .= '## ' . $section['heading'] . "\n\n";
                }
                $body .= ($section['body'] ?? '') . "\n\n";
            }
            $stmt->execute([
                'slug' => $project['slug'] ?? Slug::make($project['title'] ?? 'project'),
                'title' => $project['title'] ?? 'Untitled',
                'summary' => $project['summary'] ?? null,
                'body' => trim($body),
                'source' => $project['source_url'] ?? null,
                'external' => $project['external_url'] ?? null,
                'featured' => !empty($project['featured']) ? 1 : 0,
                'img' => $this->mediaFromProjectImage($project['featured_image'] ?? null),
                'status' => 'published',
                'published' => $project['publishedAt'] ?? $this->publishedAt($i),
            ]);
            $projectId = (int) $this->pdo->lastInsertId();
            foreach (($project['tags'] ?? []) as $label) {
                $termId = $this->termId('tag', Slug::make($label), $label);
                $this->linkTerm('project', $projectId, $termId);
            }
        }
    }

    /** @param array<string,array<int,string>> $taxonomies */
    private function linkTaxonomies(string $type, int $contentId, array $taxonomies): void
    {
        foreach ($taxonomies as $taxonomy => $slugs) {
            foreach ($slugs as $slug) {
                $this->linkTerm($type, $contentId, $this->termId($taxonomy, $slug));
            }
        }
    }

    private function linkTerm(string $type, int $contentId, int $termId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO term_relationships (term_id, content_type, content_id)
             VALUES (:term, :type, :cid)'
        );
        $stmt->execute(['term' => $termId, 'type' => $type, 'cid' => $contentId]);
    }

    private function importResume(array $resume): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO resume_meta (name, headline, headlines, summary, contact)
             VALUES (:name, :headline, :headlines, :summary, :contact)'
        );
        $stmt->execute([
            'name' => $resume['name'] ?? '',
            'headline' => $resume['headline'] ?? null,
            'headlines' => json_encode($resume['headlines'] ?? [], JSON_UNESCAPED_SLASHES),
            'summary' => $resume['summary'] ?? null,
            'contact' => json_encode($resume['contact'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
        ]);

        $exp = $this->pdo->prepare(
            'INSERT INTO experience (company, role, start, end, location, bullets, tags, sort)
             VALUES (:company, :role, :start, :end, :location, :bullets, :tags, :sort)'
        );
        foreach (($resume['experience'] ?? []) as $i => $job) {
            $exp->execute([
                'company' => $job['company'] ?? '',
                'role' => $job['role'] ?? '',
                'start' => $job['start'] ?? null,
                'end' => $job['end'] ?? null,
                'location' => $job['location'] ?? null,
                'bullets' => json_encode($job['bullets'] ?? [], JSON_UNESCAPED_SLASHES),
                'tags' => json_encode($job['tags'] ?? [], JSON_UNESCAPED_SLASHES),
                'sort' => $i,
            ]);
        }

        $edu = $this->pdo->prepare(
            'INSERT INTO education (school, degree, start, end, location, sort)
             VALUES (:school, :degree, :start, :end, :location, :sort)'
        );
        foreach (($resume['education'] ?? []) as $i => $ed) {
            $edu->execute([
                'school' => $ed['school'] ?? '',
                'degree' => $ed['degree'] ?? '',
                'start' => $ed['start'] ?? null,
                'end' => $ed['end'] ?? null,
                'location' => $ed['location'] ?? null,
                'sort' => $i,
            ]);
        }
    }

    private function importSkills(array $categories): void
    {
        $cat = $this->pdo->prepare('INSERT INTO skill_categories (name, sort) VALUES (:name, :sort)');
        $skill = $this->pdo->prepare(
            'INSERT INTO skills (category_id, name, proficiency, sort) VALUES (:cat, :name, :prof, :sort)'
        );
        foreach ($categories as $ci => $category) {
            $cat->execute(['name' => $category['name'] ?? '', 'sort' => $ci]);
            $categoryId = (int) $this->pdo->lastInsertId();
            foreach (($category['items'] ?? []) as $si => $item) {
                $skill->execute([
                    'cat' => $categoryId,
                    'name' => $item['name'] ?? '',
                    'prof' => $item['proficiency'] ?? null,
                    'sort' => $si,
                ]);
            }
        }
    }
}
