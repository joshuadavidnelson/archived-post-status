// Jest for the CI/CD helper scripts in this directory only.
//
// Deliberately separate from the repo's jest.config.js: that config covers
// shipped plugin code in assets/js and carries coverage thresholds that
// exist to catch a suite quietly dropping the real module. This is build
// tooling, so it gets a plain node runner, no jsdom, no transform and no
// thresholds. Run it with `npm run test:workflows`.
module.exports = {
	rootDir: __dirname,
	testEnvironment: 'node',
	testMatch: [ '<rootDir>/**/*.test.js' ],
	transform: {},
};
