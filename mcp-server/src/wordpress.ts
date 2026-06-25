/**
 * Thin WordPress REST API client.
 * Auth: HTTP Basic with an Application Password (Settings → Users → Application Passwords).
 */

export interface WpConfig {
  url: string;        // e.g. http://localhost:8181
  user: string;
  appPassword: string;
}

export interface DiviPage {
  id: number;
  title: string;
  status: string;
  link: string;
  edit_link: string;
  divi_meta: Record<string, string>;
}

export interface LayoutEnvelope {
  source: string;
  format: string;
  divi_version: string;
  post_id: number;
  post_title: string;
  post_status: string;
  post_content: string;
  divi_meta: Record<string, string>;
  exported_at: string;
}

export interface ValidationResult {
  valid: boolean;
  violations: Array<{ code: string; message: string; path: string }>;
}

export interface UpdateResult {
  saved: boolean;
  valid: boolean;
  violations: Array<{ code: string; message: string; path: string }>;
  message?: string;
  page?: LayoutEnvelope;
}

export class WordPressClient {
  private readonly base: string;
  private readonly auth: string;

  constructor(config: WpConfig) {
    this.base = config.url.replace(/\/$/, '') + '/wp-json/divi5-validator/v1';
    this.auth = 'Basic ' + Buffer.from(`${config.user}:${config.appPassword}`).toString('base64');
  }

  async listPages(): Promise<{ pages: DiviPage[]; count: number }> {
    return this.get('/pages');
  }

  async getPage(id: number): Promise<LayoutEnvelope> {
    return this.get(`/pages/${id}`);
  }

  async validateLayout(postContent: string): Promise<ValidationResult> {
    return this.post('/validate', { post_content: postContent });
  }

  async updatePage(id: number, postContent: string): Promise<UpdateResult> {
    return this.put(`/pages/${id}`, { post_content: postContent });
  }

  // ---------------------------------------------------------------

  private async get<T>(path: string): Promise<T> {
    const res = await fetch(this.base + path, {
      headers: { Authorization: this.auth, Accept: 'application/json' },
    });
    return this.handleResponse<T>(res);
  }

  private async post<T>(path: string, body: unknown): Promise<T> {
    const res = await fetch(this.base + path, {
      method: 'POST',
      headers: {
        Authorization: this.auth,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
    });
    return this.handleResponse<T>(res);
  }

  private async put<T>(path: string, body: unknown): Promise<T> {
    const res = await fetch(this.base + path, {
      method: 'PUT',
      headers: {
        Authorization: this.auth,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
    });
    return this.handleResponse<T>(res);
  }

  private async handleResponse<T>(res: Response): Promise<T> {
    const data = await res.json().catch(() => ({ error: 'Non-JSON response', status: res.status }));
    if (!res.ok && res.status !== 422) {
      throw new Error(`WordPress API error ${res.status}: ${JSON.stringify(data)}`);
    }
    return data as T;
  }
}

export function clientFromEnv(): WordPressClient {
  const url = process.env.WP_URL;
  const user = process.env.WP_USER;
  const pass = process.env.WP_APP_PASSWORD;

  if (!url || !user || !pass) {
    throw new Error(
      'Missing required environment variables: WP_URL, WP_USER, WP_APP_PASSWORD\n' +
      'Set WP_APP_PASSWORD to an Application Password created in WordPress admin ' +
      '(Users → Profile → Application Passwords).'
    );
  }

  return new WordPressClient({ url, user, appPassword: pass });
}
