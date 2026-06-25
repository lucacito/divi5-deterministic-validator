import { WordPressClient } from '../wordpress.js';

export const listDiviPagesTool = {
  name: 'list_divi_pages' as const,
  description:
    'List all WordPress pages that use the Divi 5 builder. ' +
    'Returns page IDs, titles, statuses, and edit links. ' +
    'Use this to find a page ID before calling get_page_layout or update_page_layout.',
  inputSchema: {
    type: 'object' as const,
    properties: {},
    required: [] as string[],
  },
};

export async function listDiviPages(wp: WordPressClient): Promise<string> {
  const result = await wp.listPages();

  if (result.count === 0) {
    return 'No pages using the Divi 5 builder were found.';
  }

  const lines = result.pages.map(
    (p) => `ID ${p.id}: "${p.title}" (${p.status}) — ${p.link}`
  );

  return `Found ${result.count} Divi 5 page(s):\n\n${lines.join('\n')}`;
}
