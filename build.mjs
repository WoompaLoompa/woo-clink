import * as esbuild from 'esbuild';
import fs from 'fs';

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
  {
    entryPoints: [ 'assets/js/clink-price-converter.js' ],
    bundle: false,
    minify: true,
    outfile: 'assets/js/clink-price-converter.min.js',
    target: [ 'es2020' ],
    format: 'iife',
  },
];

function stripGistUrl( file ) {
  let content = fs.readFileSync( file, 'utf8' );

  const gistRe  = /`https:\/\/gist\.github\.com\/\$\{[^}]+\}\/\$\{[^}]+\}\/raw`/;
  const verifyRe = /`Verifying that I control the following Nostr public key: \$\{[^}]+\}`/;

  if ( gistRe.test( content ) || verifyRe.test( content ) ) {
    content = content.replace( gistRe, '`/dev/null/gist/${e}/${n}/raw`' );
    content = content.replace( verifyRe, '`gist-verify:${t}`' );
    fs.writeFileSync( file, content, 'utf8' );
    console.log( '  Stripped remote URLs from:', file );
  }
}

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

  for ( const cfg of builds ) {
    if ( cfg.outfile && cfg.outfile.includes( 'clink-checkout' ) ) {
      stripGistUrl( cfg.outfile );
    }
  }
}
