<?php
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

defined('MOODLE_INTERNAL') || die();


class filter_rtmp extends moodle_text_filter
{

  // Inherited member vars
  //
  ///** @var object The context we are in. */
  //protected $context;
  ///** @var array Any local configuration for this filter in this context. */
  //protected $localconfig;

    /** @var bool True if currently filtering trusted text */
    private $trusted;
    /** @var core_media_renderer Media renderer */
    private $mediarenderer;
    /** @var string Partial regex pattern indicating possible embeddable content */
    private $embedmarkers;



    /**
     * Search for specific href values within <a> tags beginning
     * with rtmp protocol and modify the tag for the flowplayer
     * 
     * @param string    $text
     * @param array     $options
     * 
     * @return string
     * @uses $CFG, $PAGE 
     */
    public function filter($text, array $options = array())
    {
        global $CFG, $PAGE;
        static $js_init_called = false;



        if (!is_string($text) or empty($text)) {
            // non string data can not be filtered anyway
            return $text;
        }

        if (stripos($text, '</a>') === false) {
            // Performance shortcut - if not </a> tag, nothing can match.
            return $text;
        }

        if (!$this->mediarenderer) {
            $this->mediarenderer = $PAGE->get_renderer('filter_rtmp');
            $this->embedmarkers = $this->mediarenderer->get_embeddable_markers();
        }

        // Check permissions, will be examined in the preg_replace callback
        $this->trusted = !empty($options['noclean']) or !empty($CFG->allowobjectembed);

        // Handle all links that contain any 'embeddable' marker text (it could
        // do all links, but the embeddable markers thing should make it faster
        // by meaning for most links it doesn't drop into PHP code).
        $regex = '~<a\s[^>]*href="(rtmp:\/\/[^"]*(?:' . $this->embedmarkers . ')[^"]*)"[^>]*>([^>]*)</a>~is';
        $newtext = preg_replace_callback($regex, array($this, 'callback'), $text);

        // If no joy then return original
        if (empty($newtext)) {
            return $text;
        }

        // Only want one init per page, so one and done
        if (!$js_init_called) {

            $js_init_called = true;

            if (empty($CFG->cachetext)) {
                // If not caching filter output, then cleaner
                // to add a page requirement
                $PAGE->requires->js_init_call('M.filter_rtmp.init', null, true, array('name' => 'filter_rtmp', 'fullpath' => '/filter/rtmp/module.js', 'requires' => array('node')));
            } else {
                $newtext .= "\n"
                         . html_writer::script("M.yui.add_module({ filter_rtmp: { name: 'filter_rtmp', fullpath: '{$CFG->wwwroot}/filter/rtmp/module.js', requires: ['node'] }});\n"
                         .                     "YUI().use('node', function(Y) { Y.on('domready', function() { Y.use('filter_rtmp', function(Y) { M.filter_rtmp.init(Y); }); }); });");
            }

        }

        return $newtext;

    }

    /**
     * Callback routine passed to preg_replace_callback(). Replace
     * link with embedded content, if supported.
     *
     * @param array     $matches        Array provided by preg_replace_callback. [0] original text, [1] href attribute, [2] anchor label.
     * @return string
     */
    private function callback(array $matches)
    {


        // Check if we ignore it.
        if (preg_match('/class="[^"]*nomediaplugin/i', $matches[0])) {
            return $matches[0];
        }

        // Get name, use default if empty
        $name = trim($matches[2]);
        if (empty($name)) {
            $name = 'Media Stream (RTMP)';
        }

        // Split provided URL into alternatives.
        $urls = self::split_alternatives($matches[1], $width, $height);

        $options = array();

        // Trusted if $CFG allowing object embed and 'noclean'
        // was passed to the filter method as an option
        if ($this->trusted) {
            $options[core_media::OPTION_TRUSTED] = true;
        }

        // We could test whether embed is possible using can_embed, but to save
        // time, let's just embed it with the 'fallback to blank' option which
        // does most of the same stuff anyhow.
        $options[core_media::OPTION_FALLBACK_TO_BLANK] = true;

        // NOTE: Options are not passed through from filter because the 'embed'
        // code does not recognise filter options (it's a different kind of
        // option-space) as it can be used in non-filter situations.
        $result = $this->mediarenderer->embed_alternatives($urls, $name, $width, $height, $options);

        // If something was embedded, return it, otherwise return original.
        return (empty($result) ? $matches[0] : $result);

    }

    
    /**
     * Lifted from lib/medialib.php. Need to omit the call to clean_param
     * until 'rtmp' is added as a valid scheme in the Moodle core libs.
     * 
     * @param string $combinedurl String of 1 or more alternatives separated by #
     * @param int $width Output variable: width (will be set to 0 if not specified)
     * @param int $height Output variable: height (0 if not specified)
     * @return array Array of 1 or more moodle_url objects
     */
    private static function split_alternatives($combinedurl, &$width, &$height)
    {

        $urls = explode('#', $combinedurl);
        $width = 0;
        $height = 0;
        $returnurls = array();

        foreach ($urls as $url) {
            $matches = null;
    
            // You can specify the size as a separate part of the array like
            // #d=640x480 without actually including a url in it.
            if (preg_match('/^d=([\d]{1,4})x([\d]{1,4})$/i', $url, $matches)) {
                $width  = $matches[1];
                $height = $matches[2];
                continue;
            }
    
            // Can also include the ?d= as part of one of the URLs (if you use
            // more than one they will be ignored except the last).
            if (preg_match('/\?d=([\d]{1,4})x([\d]{1,4})$/i', $url, $matches)) {
                $width  = $matches[1];
                $height = $matches[2];
    
                // Trim from URL.
                $url = str_replace($matches[0], '', $url);
            }
    
            // Clean up url. But first substitute the rtmp scheme with
            // http to allow validation against everything else, then
            // put the rtmp back.
            $url = preg_replace('/^rtmp:\/\//i', 'http://', $url, 1);
            $url = clean_param($url, PARAM_URL);
            if (empty($url)) {
                continue;
            }
            $url = preg_replace('/^http:\/\//', 'rtmp://', $url, 1);
            
            // Turn it into moodle_url object.
            $returnurls[] = new moodle_url($url);
        }
    
        return $returnurls;
    }

} // class filter_rtmp

