module.exports = function(grunt, config, parameters, done) {
	var workFolder = parameters.releaseWorkFolder || './release';
	function endForError(e) {
		process.stderr.write(e.message || e);
		done(false);
	}
	try {
		var fs = require('fs'),
			path = require('path')
			download = require('download'),
			c5fs = require('../../libraries/fs'),
			shell = require('shelljs');
		if(c5fs.isDirectory(workFolder)) {
			process.stdout.write('Removing working folder... ');
			shell.rm('-rf', workFolder);
			if(c5fs.isDirectory(workFolder)) {
				throw new Error('Unable to remove ' + workFolder);
			}
			process.stdout.write('done.\n')
		}
		done();
	}
	catch(e) {
		endForError(e);
		return;
	}
};