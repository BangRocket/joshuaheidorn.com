import type { APIRoute } from "astro";
import { getSiteSettings } from "emdash";

import { resolveBlogSiteIdentity } from "../../utils/site-identity";
import projectsData from "../../data/projects.json";

export const prerender = false;

export const GET: APIRoute = async ({ site, url }) => {
	const siteUrl = site?.toString() || url.origin;
	const { siteTitle } = resolveBlogSiteIdentity(await getSiteSettings());

	const sorted = [...projectsData.projects]
		.filter((p) => p.publishedAt)
		.sort((a, b) => {
			const da = a.publishedAt ? new Date(a.publishedAt).getTime() : 0;
			const db = b.publishedAt ? new Date(b.publishedAt).getTime() : 0;
			return db - da;
		})
		.slice(0, 40);

	const items = sorted
		.map((project) => {
			const pubDate = new Date(project.publishedAt!).toUTCString();
			const projectUrl = `${siteUrl}/projects/${project.slug}`;
			const title = escapeXml(project.title || "Untitled");
			const description = escapeXml(project.summary || "");
			return `    <item>
      <title>${title}</title>
      <link>${projectUrl}</link>
      <guid isPermaLink="true">${projectUrl}</guid>
      <pubDate>${pubDate}</pubDate>
      <description>${description}</description>
    </item>`;
		})
		.join("\n");

	const rss = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>${escapeXml(siteTitle)} — Projects</title>
    <description>Selected work by ${escapeXml(siteTitle)}</description>
    <link>${siteUrl}/projects</link>
    <atom:link href="${siteUrl}/projects/rss.xml" rel="self" type="application/rss+xml"/>
    <language>en-us</language>
    <lastBuildDate>${new Date().toUTCString()}</lastBuildDate>
${items}
  </channel>
</rss>`;

	return new Response(rss, {
		headers: {
			"Content-Type": "application/rss+xml; charset=utf-8",
			"Cache-Control": "public, max-age=3600",
		},
	});
};

const XML_ESCAPE_PATTERNS = [
	[/&/g, "&amp;"],
	[/</g, "&lt;"],
	[/>/g, "&gt;"],
	[/"/g, "&quot;"],
	[/'/g, "&apos;"],
] as const;

function escapeXml(str: string): string {
	let result = str;
	for (const [pattern, replacement] of XML_ESCAPE_PATTERNS) {
		result = result.replace(pattern, replacement);
	}
	return result;
}
