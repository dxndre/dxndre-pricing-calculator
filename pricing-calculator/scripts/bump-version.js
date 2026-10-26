'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );

const root = path.resolve( __dirname, '..' );

const phpPath = path.join( root, 'pricing-calculator.php' );
const packagePath = path.join( root, 'package.json' );

const type = process.argv[2];
const allowedTypes = [ 'patch', 'minor', 'major' ];

if ( ! allowedTypes.includes( type ) ) {
	console.error( 'Invalid version type. Use patch, minor or major.' );
	process.exit( 1 );
}

try {
	// Read and validate the existing versions.
	const php = fs.readFileSync( phpPath, 'utf8' );
	const pkg = JSON.parse(
		fs.readFileSync( packagePath, 'utf8' )
	);

	const versionPattern = /^([ \t]*\*[ \t]*Version:[ \t]*)(\d+\.\d+\.\d+)([ \t]*)$/m;

	const match = php.match( versionPattern );

	if ( ! match ) {
		throw new Error( 'WordPress plugin version header not found.' );
	}

	if ( match[2] !== pkg.version ) {
		throw new Error(
			`Version mismatch: PHP ${ match[2] }, NPM ${ pkg.version }. Synchronise these before continuing.`
		);
	}

	console.log( `Current version: v${ pkg.version }` );

	// Compile SCSS before changing any versions.
	console.log( 'Compiling SCSS...' );

	execFileSync(
		'npm',
		[ 'run', 'build:css' ],
		{
			cwd: root,
			stdio: 'inherit'
		}
	);

	// Update package.json and package-lock.json.
	const newVersion = execFileSync(
		'npm',
		[
			'version',
			type,
			'--no-git-tag-version',
			'--ignore-scripts'
		],
		{
			cwd: root,
			encoding: 'utf8'
		}
	).trim().replace( /^v/, '' );

	// Synchronise the WordPress plugin header.
	const updatedPhp = php.replace(
		versionPattern,
		( match, prefix, oldVersion, suffix ) => {
			return `${ prefix }${ newVersion }${ suffix }`;
		}
	);

	fs.writeFileSync( phpPath, updatedPhp );

	console.log( '--------------------------------' );
	console.log( 'DXNDRE Pricing Calculator' );
	console.log( `Version: ${ pkg.version } → ${ newVersion }` );
	console.log( 'PHP header updated' );
	console.log( 'NPM package updated' );
	console.log( 'Package lock synchronised' );
	console.log( 'SCSS compiled successfully' );
	console.log( '--------------------------------' );

} catch ( error ) {
	console.error( 'Version update failed:', error.message );
	console.error(
		'Check the current file versions before retrying to avoid incrementing twice.'
	);
	process.exitCode = 1;
}
