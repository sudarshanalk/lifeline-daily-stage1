// Repository paths shared by every test family. ROOT is the repository root regardless of cwd.
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// LLD_ROOT points the guards at a sandbox copy of the repository (TC-UI1-11-AS-02 proves copy-hash fails on a changed string).
export const ROOT = process.env.LLD_ROOT ? resolve(process.env.LLD_ROOT) : resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
export const TOOLS_DIR = resolve(ROOT, 'site', 'public_html', 'tools');
export const TOOL_FILES = {
  protein: 'protein-requirement-tool.html',
  diabetes: 'diabetes-risk-tool.html',
};
export const toolPath = (slug) => resolve(TOOLS_DIR, TOOL_FILES[slug]);
