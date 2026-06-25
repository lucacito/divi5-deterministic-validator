import { WordPressClient } from '../wordpress.js';

export const updatePageLayoutTool = {
  name: 'update_page_layout' as const,
  description:
    'Validate a new Divi 5 layout and, if valid, save it to a WordPress page. ' +
    'If validation fails the page is NOT updated — the violations are returned instead. ' +
    'This is the only safe way to write Divi layouts; never bypass this with direct DB writes.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      page_id: {
        type: 'number',
        description: 'The WordPress page ID to update',
      },
      post_content: {
        type: 'string',
        description: 'The new Divi 5 post_content (Gutenberg block HTML)',
      },
    },
    required: ['page_id', 'post_content'],
  },
};

export async function updatePageLayout(
  wp: WordPressClient,
  args: { page_id: number; post_content: string }
): Promise<string> {
  const result = await wp.updatePage(args.page_id, args.post_content);

  if (result.saved) {
    const title = result.page?.post_title ?? `ID ${args.page_id}`;
    return `SAVED — page "${title}" updated successfully. Layout passed validation.`;
  }

  const lines = result.violations.map(
    (v) => `  [${v.code}] ${v.message}\n    at: ${v.path}`
  );

  return (
    `NOT SAVED — layout failed validation. Page was not modified.\n\n` +
    `${result.violations.length} violation(s):\n\n${lines.join('\n\n')}`
  );
}
