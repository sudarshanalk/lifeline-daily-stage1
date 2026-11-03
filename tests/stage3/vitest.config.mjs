import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    root: new URL('../..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'),
    include: ['tests/stage3/**/*.test.mjs'],
    testTimeout: 60000,
  },
});
