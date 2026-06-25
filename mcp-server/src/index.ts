#!/usr/bin/env node
/**
 * Divi 5 Validator MCP Server
 *
 * Exposes four tools for AI agents to safely read and edit Divi 5 pages:
 *   list_divi_pages      — find pages using the Divi 5 builder
 *   get_page_layout      — read a page's current layout
 *   validate_layout      — check a layout without saving
 *   update_page_layout   — validate then save (refuses if invalid)
 *
 * Required env vars:
 *   WP_URL           e.g. http://localhost:8181
 *   WP_USER          WordPress username
 *   WP_APP_PASSWORD  Application Password (Users → Profile → Application Passwords)
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import { clientFromEnv } from './wordpress.js';
import { listDiviPagesTool, listDiviPages } from './tools/listDiviPages.js';
import { getPageLayoutTool, getPageLayout } from './tools/getPageLayout.js';
import { validateLayoutTool, validateLayout } from './tools/validateLayout.js';
import { updatePageLayoutTool, updatePageLayout } from './tools/updatePageLayout.js';

const server = new Server(
  { name: 'divi5-validator', version: '1.0.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
    listDiviPagesTool,
    getPageLayoutTool,
    validateLayoutTool,
    updatePageLayoutTool,
  ],
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const wp = clientFromEnv();
  const { name, arguments: args } = request.params;

  let text: string;

  try {
    switch (name) {
      case 'list_divi_pages':
        text = await listDiviPages(wp);
        break;

      case 'get_page_layout':
        text = await getPageLayout(wp, args as { page_id: number });
        break;

      case 'validate_layout':
        text = await validateLayout(wp, args as { post_content: string });
        break;

      case 'update_page_layout':
        text = await updatePageLayout(
          wp,
          args as { page_id: number; post_content: string }
        );
        break;

      default:
        return {
          content: [{ type: 'text', text: `Unknown tool: ${name}` }],
          isError: true,
        };
    }
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return {
      content: [{ type: 'text', text: `Error: ${msg}` }],
      isError: true,
    };
  }

  return { content: [{ type: 'text', text }] };
});

const transport = new StdioServerTransport();
await server.connect(transport);
