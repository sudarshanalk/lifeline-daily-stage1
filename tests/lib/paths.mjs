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
// Stage 2 (FR2-39, D53): the Healthy Weight Check is guarded like the two Stage 1 tools (freeze, wording, provenance,
// sign-off) but is not a Stage 1 tool, so the Stage 1 requirement tests keep iterating TOOL_FILES only.
export const ALL_TOOL_FILES = { ...TOOL_FILES, 'healthy-weight': 'healthy-weight-tool.html' };
export const GUARDED_TOOLS = Object.keys(ALL_TOOL_FILES);
export const toolPath = (slug) => resolve(TOOLS_DIR, ALL_TOOL_FILES[slug]);
