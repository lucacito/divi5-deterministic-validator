import { WordPressClient } from '../wordpress.js';

export const getPageLayoutTool = {
  name: 'get_page_layout' as const,
  description:
    'Get the current Divi 5 layout for a WordPress page. ' +
    'Returns the full post_content (Gutenberg block HTML) and metadata. ' +
    'Use this before editing a layout so you know the current state.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      page_id: {
        type: 'number',
        description: 'The WordPress page ID (from list_divi_pages)',
      },
    },
    required: ['page_id'],
  },
};

export async function getPageLayout(
  wp: WordPressClient,
  args: { page_id: number }
): Promise<string> {
  const layout = await wp.getPage(args.page_id);

  return JSON.stringify(layout, null, 2);
}
