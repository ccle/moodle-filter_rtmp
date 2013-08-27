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

    _js_flowplayer: M.cfg.wwwroot + filter_rtmp_flowplayer_js,
    _swf_cfg_base:  M.cfg.wwwroot + filter_rtmp_flowplayer_swf,
    _swf_cfg_rtmp:  M.cfg.wwwroot + filter_rtmp_flowplayer_rtmp,
    _min_video_width: 300,
    _default_volume: 80,

    
    init_flowplayer_rtmp_video: function(node) {

        // Get the attributes for the node
        var playerId = node.get('id');
        if (typeof(playerId) == 'undefined') { return; }

        var mediaHeight = node.getAttribute('data-media-height');
        if (typeof(mediaHeight) == 'undefined') { mediaHeight = 0; }
        var mediaWidth  = node.getAttribute('data-media-width');
        if (typeof(mediaWidth) == 'undefined') { mediaWidth = 0; }
        var mediaAutosize = node.getAttribute('data-media-autosize');
        if (typeof(mediaAutosize) == 'undefined') { mediaAutosize = true; }
        else { mediaAutosize = (isNaN(mediaAutosize) ? false : parseInt(mediaAutosize) == 1); }

        var flashConfig = { src: M.filter_rtmp._swf_cfg_base };
        // If dimensions specified, pass along in Flash configs
        if (mediaHeight > 0 && mediaWidth > 0) {
            flashConfig.width = mediaWidth; flashConfig.height = mediaHeight;
        }

        var baseClip = {
            provider: 'rtmp', myMeta: { autosize: mediaAutosize, resized: false },
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
        };

        var flowConfig = {
            plugins: {
                controls: { autoHide: true }, rtmp: { url: M.filter_rtmp._swf_cfg_rtmp, objectEncoding: 0 }
            },
            clip: baseClip,
            onLoad: function() {
                this.setVolume(M.filter_rtmp._default_volume); this.unmute();
            }
        };

        var playlist = window[playerId];
        if (playlist.length == 1) {
            baseClip.url = playlist[0].url;
            baseClip.netConnectionUrl = playlist[0].netConnectionUrl;
            flowplayer(playerId, flashConfig, flowConfig);
        } else {
            M.filter_rtmp.attachPlaylistPlugin();
            M.filter_rtmp.Y.one('.filter_rtmp_video_playlist.' + playerId).setStyle('height', mediaHeight);
            flowConfig.playlist = playlist;
            flowplayer(playerId, flashConfig, flowConfig).playlist('.filter_rtmp_video_playlist.' + playerId, M.filter_rtmp.Y);
        }

    }, // init_flowplayer_rtmp_video


    init_flowplayer_rtmp_audio: function(node) {

        // Get the attributes for the node
        var playerId = node.get('id');
        if (typeof(playerId) == 'undefined') { return; }

        var flashConfig = { src: M.filter_rtmp._swf_cfg_base };
        var flowConfig = {
            plugins: {
                controls: { autoHide: 'never', fullscreen: false, next: false, previous: false, scrubber: true,
                            play: true, pause: true, volume: true, mute: true, backgroundGradient: [0.5,0,0.3],
                            controlall: true, height: '100%', time: true },
                rtmp: { url: M.filter_rtmp._swf_cfg_rtmp, durationFunc: 'getStreamLength' }
            },
            clip: { provider: 'rtmp', autoPlay: false },
            play: null,
            onLoad: function() {
                this.setVolume(M.filter_rtmp._default_volume); this.unmute();
            }
        };
        
        var playlist = window[playerId];
        if (playlist.length == 1) {
            flowConfig.clip.url = playlist[0].url;
            flowConfig.clip.netConnectionUrl = playlist[0].netConnectionUrl;
            flowplayer(playerId, flashConfig, flowConfig);
        } else {
            M.filter_rtmp.attachPlaylistPlugin();
            flowConfig.playlist = playlist;
            flowplayer(playerId, flashConfig, flowConfig).playlist('.filter_rtmp_audio_playlist.' + playerId, M.filter_rtmp.Y);
        }
        
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

    /* Alternate playlist plugin for Flowplayer, based upon
     * their standard playlist plugin by Tero Piirainen, but
     * uses YUI rather than jQuery, and is stripped down to
     * essentials for use with rtmp_filter
     */
    attachPlaylistPlugin: function() {
    $f.addPlugin("playlist", function(selector, Y) {

        var thisPlayer = this, listContainer = Y.one(selector);
        if (null == listContainer) return thisPlayer;

        function buildPlaylist()
        {
            listContainer.detach('click'); listContainer.empty();
            Y.Array.each(thisPlayer.getPlaylist(), function(clip) {
                listContainer.appendChild(renderListItem(clip));
            });
            listContainer.delegate('click', function(e) {
                e.preventDefault();
                play(this);
            }, 'a');
            listItems = listContainer.all("a");
        }

        function renderListItem(clip)
        {
            return opts.template.replace('$%7B', '{').replace('%7D', '}').replace('$\{url\}', clip.url).replace('$\{title\}', clip.title);
        }

        function play(listItem)
        {
            if (listItem.hasClass(opts.playingClass) || listItem.hasClass(opts.pausedClass)) {
                thisPlayer.toggle();
            } else {
                listItem.addClass(opts.progressClass);
                thisPlayer.play(listItems.indexOf(listItem));
            }
        }

        function clearCSS(node)
        {
            if (null == node) return;
            node.removeClass(opts.playingClass).removeClass(opts.pausedClass).removeClass(opts.progressClass).removeClass(opts.stoppedClass);
        }

        var opts = { playingClass: 'playing', pausedClass: 'paused', progressClass:'progress', stoppedClass:'stopped', template: '<a href="${url}">${title}</a>', loop: false, continuousPlay: false, playOnClick: true };
        var listItems = null;

        thisPlayer.onBegin(function(clip) {
            listItems.each(clearCSS); listItems.item(clip.index).addClass(opts.playingClass);
        });
        thisPlayer.onPause(function(clip) {
            listItems.item(clip.index).removeClass(opts.playingClass).addClass(opts.pausedClass);
        });
        thisPlayer.onResume(function(clip) {
            listItems.item(clip.index).removeClass(opts.pausedClass).addClass(opts.playingClass);
        });
        thisPlayer.onUnload(function() {
            listItems.each(clearCSS);
        });

        buildPlaylist();

        return thisPlayer;

    }); // $f.addPlugin('playlist')
    }, // attachPlaylistPlugin

}; // M.filter_rtmp
