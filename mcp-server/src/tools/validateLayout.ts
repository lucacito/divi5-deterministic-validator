import { WordPressClient } from '../wordpress.js';

export const validateLayoutTool = {
  name: 'validate_layout' as const,
  description:
    'Validate a Divi 5 layout (post_content) without saving it. ' +
    'Returns PASS or FAIL with a list of specific violations and their paths. ' +
    'Always validate before calling update_page_layout.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      post_content: {
        type: 'string',
        description: 'The Divi 5 post_content string (Gutenberg block HTML)',
      },
    },
    required: ['post_content'],
  },
};

export async function validateLayout(
  wp: WordPressClient,
  args: { post_content: string }
): Promise<string> {
  const result = await wp.validateLayout(args.post_content);

  if (result.valid) {
    return 'PASS — layout is valid. No violations found.';
  }

  const lines = result.violations.map(
    (v) => `  [${v.code}] ${v.message}\n    at: ${v.path}`
  );

  return `FAIL — ${result.violations.length} violation(s):\n\n${lines.join('\n\n')}`;
}
