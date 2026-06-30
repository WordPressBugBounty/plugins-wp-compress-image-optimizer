module.exports = function (grunt) {

    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),
        concat: {
            js: {
                files: {
                    // Optimizer JS
                    'assets/js/dist/optimizer.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/cdn/optimizer-functions.js', 'assets/js/src/cdn/only-optimizer.js'],
                    // Optimizer JS - Pixel Ratio
                    'assets/js/dist/optimizer.pixel.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/cdn/optimizer-functions.js', 'assets/js/src/cdn/only-optimizer.js'],
                    // Optimizer No Lazy
                    'assets/js/dist/optimizer.adaptive.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/cdn/optimizer-functions.js', 'assets/js/src/only-optimizer-adaptive.js'],
                    // Optimizer No Lazy - Pixel Ratio
                    'assets/js/dist/optimizer.adaptive.pixel.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/cdn/optimizer-functions.js', 'assets/js/src/only-optimizer-adaptive.js'],
                    // Optimizer Just Local
                    'assets/js/dist/optimizer.local.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/local/script.js'],
                    // Optimizer Just Local - Pixel Ratio
                    'assets/js/dist/optimizer.local.pixel.js': ['assets/js/src/isMobile.js', 'assets/js/src/pixelRatio.js', 'assets/js/src/delayJS.js', 'assets/js/src/local/script.js'],
                    // Optimizer Local - Lazy
                    'assets/js/dist/optimizer.local-lazy.js': ['assets/js/src/isMobile.js', 'assets/js/src/delayJS.js', 'assets/js/src/local/lazy.js'],
                    // Optimizer Local - Lazy
                    'assets/js/dist/optimizer.local-lazy.pixel.js': ['assets/js/src/isMobile.js', 'assets/js/src/pixelRatio.js', 'assets/js/src/delayJS.js', 'assets/js/src/local/lazy.js']
                }
            }
        },
        uglify: {
            build: {
                options: {
                    sourceMap: false,
                    sourceMapName: 'optimizer.map'
                },
                files: {
                    'assets/js/dist/optimizer.adaptive.min.js': 'assets/js/dist/optimizer.adaptive.js',
                    'assets/js/dist/optimizer.adaptive.pixel.min.js': 'assets/js/dist/optimizer.adaptive.pixel.js',
                    'assets/js/dist/optimizer.min.js': 'assets/js/dist/optimizer.js',
                    'assets/js/dist/optimizer.pixel.min.js': 'assets/js/dist/optimizer.pixel.js',
                    'assets/js/dist/optimizer.local.min.js': 'assets/js/dist/optimizer.local.js',
                    'assets/js/dist/optimizer.local.pixel.min.js': 'assets/js/dist/optimizer.local.pixel.js',
                    'assets/js/dist/optimizer.local-lazy.min.js': 'assets/js/dist/optimizer.local-lazy.js',
                    'assets/js/dist/optimizer.local-lazy.pixel.min.js': 'assets/js/dist/optimizer.local-lazy.pixel.js'
                }
            }
        },
    });

    // Load the plugin that provides the "uglify" task.
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-concat');

    // Default task(s).
    grunt.registerTask('default', ['concat','uglify']);

};