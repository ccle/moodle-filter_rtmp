// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *  RTMP streaming media filter plugin
 *
 *  This filter will replace any rtmp links to a media file with
 *  a media plugin that plays that media inline
 *
 * @package    filter_rtmp
 * @author     Lacey Vickery, Fred Woolard (based on mediaplugin filter {@link http://moodle.com})
 * @copyright  2012 Appalachian State University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

M.filter_rtmp = {

    Y: null, transaction: [],

    _js_flowplayer: M.cfg.wwwroot + '/lib/flowplayer/flowplayer-3.2.11.min.js',
    _swf_cfg_base:  M.cfg.wwwroot + '/lib/flowplayer/flowplayer-3.2.14.swf',
    _swf_cfg_rtmp:  M.cfg.wwwroot + '/filter/rtmp/flowplayer.rtmp-3.2.12.swf',
    _min_video_width: 300,
    _default_volume: 80,

    
    init_flowplayer_rtmp_video: function(node) {

        // Get the attributes for the node
        var mediaId = node.get('id');
        if (typeof(mediaId) == 'undefined') { return; }
        var mediaConx = node.getAttribute('data-media-conx');
        if (typeof(mediaConx) == 'undefined') { return; }
        var mediaPath = node.getAttribute('data-media-path');
        if (typeof(mediaPath) == 'undefined') { return; }
        var mediaHeight = node.getAttribute('data-media-height');
        if (typeof(mediaHeight) == 'undefined') { mediaHeight = 0; }
        var mediaWidth  = node.getAttribute('data-media-width');
        if (typeof(mediaWidth) == 'undefined') { mediaWidth  = 0; }
        var mediaAutosize = node.getAttribute('data-media-autosize');
        if (typeof(mediaAutosize) == 'undefined') { mediaAutosize  = true; }
        else { mediaAutosize = (isNaN(mediaAutosize) ? false : parseInt(mediaAutosize) == 1); }

        var flashConfig = { src: M.filter_rtmp._swf_cfg_base };

        // If dimensions specified, pass along in Flash configs
        if (mediaHeight > 0 && mediaWidth > 0) {
            flashConfig.width = mediaWidth; flashConfig.height = mediaHeight;
        }

        // Apply the flowplayer
        flowplayer(mediaId, flashConfig, {
            plugins: {
                controls: { autoHide: true }, rtmp: { url: M.filter_rtmp._swf_cfg_rtmp }
            },
            clip: {
                provider: 'rtmp', url: mediaPath, netConnectionUrl: mediaConx,
                myMeta: { autosize: mediaAutosize, resized: false },
                autoPlay: false, autoBuffering: true, scaling: 'fit', 
                onMetaData: function(clip) {
                    // Get out if no autosizing or already resized
                    if (!clip.myMeta.autosize || clip.myMeta.resized) {
                        return;
                    }
                    clip.myMeta.resized = true;
                    // Default dimensions (from Flash plugin?)
                    var width = clip.width, height = clip.height;
                    // Prefer dimensions in the clip metadata
                    if (typeof(clip.metaData.width) != 'undefined' && typeof(clip.metaData.height) != 'undefined') {
                        width = clip.width; height = clip.height;
                    }
                    // If too small, adjust but keep same aspect ratio
                    if (width < M.filter_rtmp._min_video_width) {
                        height = (height/width) * M.filter_rtmp._min_video_width;
                        width  = M.filter_rtmp._min_video_width;
                    }
                    this._api().width = width; this._api().height = height;
                }
            },
            onLoad: function() {
                this.setVolume(M.filter_rtmp._default_volume); this.unmute();
            }
        });

    }, // init_flowplayer_rtmp_video


    init_flowplayer_rtmp_audio: function(node) {

        // Get the attributes for the node
        var mediaId = node.get('id');
        if (typeof(mediaId) == 'undefined')     { return; }
        var mediaConx = node.getAttribute('data-media-conx');
        if (typeof(mediaConx) == 'undefined')   { return; }
        var mediaPath = node.getAttribute('data-media-path');
        if (typeof(mediaPath) == 'undefined')   { return; }

        // Apply the flowplayer
        flowplayer(mediaId, M.filter_rtmp._swf_cfg_base, {
            plugins: {
                controls: { autoHide: 'never', fullscreen: false, next: false, previous: false, scrubber: true,
                            play: true, pause: true, volume: true, mute: true, backgroundGradient: [0.5,0,0.3],
                            controlall: true, height: 20, time: true },
                rtmp: { url: M.filter_rtmp._swf_cfg_rtmp, durationFunc: 'getStreamLength' }
            },
            clip: { provider: 'rtmp', autoPlay: false, url: mediaPath, netConnectionUrl: mediaConx },
            play: null,
            onLoad: function() {
                this.setVolume(M.filter_rtmp._default_volume); this.unmute();
            }
        });
        
    }, // init_flowplayer_rtmp_audio


    init: function(Y) {

        this.Y = Y;

        // Check to see if flowplayer.js loaded, if not ask YUI
        // to go fetch it
        var useModules = ['node'];
        if (typeof (flowplayer) == 'undefined') {
            var loader = new Y.Loader({ modules: { yflowplayer: { fullpath: this._js_flowplayer } }, require: ['yflowplayer'] });
            useModules.push('yflowplayer');
        }
        // Need a new sandbox instance to which to attach the
        // flowplayer module (in the case where the flowplayer
        // script had not yet been loaded by javascript-static
        YUI().use(useModules, function() {
            // Find nodes to which flowplayer should be applied
            Y.all('.filter_rtmp_video').each(M.filter_rtmp.init_flowplayer_rtmp_video);
            Y.all('.filter_rtmp_audio').each(M.filter_rtmp.init_flowplayer_rtmp_audio);
        });

    }, // init

};

