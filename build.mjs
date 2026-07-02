import * as esbuild from 'esbuild';
import { writeFileSync } from 'fs';

const isWatch = process.argv.includes('--watch');

const config = {
  entryPoints: ['assets/js/clink-checkout.js'],
  bundle: true,
  minify: true,
  outfile: 'assets/js/clink-checkout.min.js',
  target: ['es2020'],
  format: 'iife',
  globalName: 'ClinkCheckout',
  loader: {
    '.wasm': 'empty',
  },
};

if (isWatch) {
  const ctx = await esbuild.context(config);
  await ctx.watch();
  console.log('Watching for changes...');
} else {
  await esbuild.build(config);
  console.log('Build complete: assets/js/clink-checkout.min.js');
}
