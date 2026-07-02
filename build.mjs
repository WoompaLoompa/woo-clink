import * as esbuild from 'esbuild';

const isWatch = process.argv.includes( '--watch' );

const builds = [
  {
    entryPoints: [ 'assets/js/clink-checkout.js' ],
    bundle: true,
    minify: true,
    outfile: 'assets/js/clink-checkout.min.js',
    target: [ 'es2020' ],
    format: 'iife',
    globalName: 'ClinkCheckout',
    loader: { '.wasm': 'empty' },
  },
  {
    entryPoints: [ 'assets/js/clink-blocks.js' ],
    bundle: false,
    minify: true,
    outfile: 'assets/js/clink-blocks.min.js',
    target: [ 'es2020' ],
    format: 'iife',
  },
];

if ( isWatch ) {
  for ( const cfg of builds ) {
    const ctx = await esbuild.context( cfg );
    await ctx.watch();
  }
  console.log( 'Watching for changes...' );
} else {
  for ( const cfg of builds ) {
    await esbuild.build( cfg );
    console.log( 'Build complete:', cfg.outfile );
  }
}
