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

require_once("{$CFG->libdir}/medialib.php");


class filter_rtmp_renderer extends core_media_renderer
{

    /** @var array Array of available 'player' objects */
    private $players;
    /** @var string Regex pattern for links which may contain embeddable content */
    private $embeddablemarkers;


    /**
     * Obtains a raw list of player objects that includes objects regardless
     * of whether they are disabled or not, and without sorting.
     *
     * You can override this in a subclass if you need to add additional
     * players.
     *
     * The return array is be indexed by player name to make it easier to
     * remove players in a subclass.
     *
     * @return array $players Array of core_media_player objects in any order
     */
    protected function get_players_raw()
    {
        return array(
            'audio'   => new filter_rtmp_player_audio(),
            'video'   => new filter_rtmp_player_video(),
            'link'    => new filter_rtmp_player_link()
        );
    }

} // class filter_rtmp_renderer



class filter_rtmp_player_video extends core_media_player
{

    const            RANK                       = 1001;
    const            EXTENSIONS                 = 'flv,mp4,f4v';

    const            DEFAULT_WIDTH              = 320;
    const            DEFAULT_HEIGHT             = 240;



    public function embed($urls, $name, $width, $height, $options)
    {

        // Unique id even across different http requests made at the same time
        // (for AJAX, iframes).
        $unique_id = "filter_rtmp_" . md5(time() . '_' . rand());

        // Compute width and height.
        $autosize = false;
        if (!$width && !$height) {
            $width     = self::DEFAULT_WIDTH;
            $height    = self::DEFAULT_HEIGHT;
            $autosize  = true;
        }

        $clip_array_javascript = "var {$unique_id} = [];";
        $clip_index = 0;
        foreach ($urls as $url) {

            // Parse the URL here to simplify the JavaScript
            // module. FlowPlayer rtmp needs the URL massaged
            // a little.
            $url_path = $url->get_path(false);
            if (0 === strpos($url_path, '/' )) {
                $url_path = substr($url_path, 1);
            }
            $path_parts = explode('/', $url_path);

            $provider = $url->get_param('provider');
            switch ($provider) {
                case "acf": /* Amazon Cloudfront */
                    $media_conx = str_replace($url_path, '', $url->out_omit_querystring()) . array_shift($path_parts) . '/' . array_shift($path_parts);
                    break;
                default:    /* Flash Media, Red5, Wowza */
                    $media_conx = str_replace($url_path, '', $url->out_omit_querystring()) . array_shift($path_parts);
            }

            // Put together the media path from the remainder of
            // the un-shifted path_parts elements
            $media_path = trim(implode('/', $path_parts));

            // Get the name from the $options, if not present there
            // take it from last segment of media path
            $media_title = isset($options['PLAYLIST_NAMES'][$url->out()])
                         ? $options['PLAYLIST_NAMES'][$url->out()]
                         : str_replace('+', ' ', array_pop($path_parts));

            // Now that the title has been referenced using the orig
            // URL, remove the provider param if it is present
            if ($provider != null) {
                $url->remove_params(array('provider'));
            }

            // If there is an extension, remove it, but in the
            // case of an mp4 leave it as well as prepend it to
            // media path
            $matches = array();
            if (preg_match('/\.(' . join('|', $this->get_supported_extensions()) . ')$/i', $media_path, $matches)) {
                switch ($matches[1]) {
                    case "mp4" :
                    case "f4v" :
                        if (0 === preg_match("/^mp4:/", $media_path)) {
                            $media_path = 'mp4:' . $media_path;
                        }
                        break;
                    default :
                        $media_path = substr($media_path, 0, 0 - strlen($matches[0]));
                }
            }

            // Append the remainder (query string) of the original URL
            $query_str  = htmlspecialchars_decode($url->get_query_string(false));
            if (!empty($query_str)) {
                $media_path .= '?' . $query_str;
            }

            $clip_array_javascript .= "\n{$unique_id}[{$clip_index}] = { netConnectionUrl: '{$media_conx}', url: '{$media_path}', title: '{$media_title}', index: {$clip_index} };";
            $clip_index++;

        } // foreach

        // Emit JavaScript with the clip information and
        // the fallback div normally containing the link
        $playlist_open = $playlist_close = '';
        if (count($urls) > 1) {
            $playlist_open  = "<div class=\"filter_rtmp_wrapper\">\n"
                            . "<div class=\"filter_rtmp_video_playlist {$unique_id}\"></div>\n";
            $playlist_close = "</div>\n";
        }

        return "\n" . html_writer::script($clip_array_javascript)
             . $playlist_open
             . html_writer::tag('div', core_media_player::PLACEHOLDER,
                 array('id' => $unique_id, 'class' => 'mediaplugin filter_rtmp_video',
                       'data-media-height' => $height, 'data-media-width' => $width, 'data-media-autosize' => $autosize))
             . $playlist_close;

    }


    public function get_supported_extensions()
    {
        return explode(',', self::EXTENSIONS);
    }


    public function get_rank()
    {
        return self::RANK;
    }

} // class filter_rtmp_player_video



class filter_rtmp_player_audio extends core_media_player
{

    const            RANK                       = 80;
    const            EXTENSIONS                 = 'mp3';



    public function embed($urls, $name, $width, $height, $options)
    {

        // Unique id even across different http requests made at the same time
        // (for AJAX, iframes).
        $unique_id = "filter_rtmp_" . md5(time() . '_' . rand());

        $clip_array_javascript = "var {$unique_id} = [];";
        $clip_index = 0;
        foreach ($urls as $url) {

            // Parse the URL here to simplify the JavaScript
            // module. FlowPlayer rtmp needs the URL massaged
            // a little.
            $url_path = $url->get_path(false);
            if (0 === strpos($url_path, '/' )) {
                $url_path = substr($url_path, 1);
            }
            $path_parts = explode('/', $url_path);

            $provider = $url->get_param('provider');
            switch ($provider) {
                case "acf": /* Amazon Cloudfront */
                    $media_conx = str_replace($url_path, '', $url->out_omit_querystring()) . array_shift($path_parts) . '/' . array_shift($path_parts);
                    break;
                default:    /* Flash Media, Red5, Wowza */
                    $media_conx = str_replace($url_path, '', $url->out_omit_querystring()) . array_shift($path_parts);
            }

            // Put together the media path from the remainder of
            // the un-shifted path_parts elements
            $media_path = trim(implode('/', $path_parts));

            // Get the name from the $options, if not present there
            // take it from last segment of media path
            $media_title = isset($options['PLAYLIST_NAMES'][$url->out()])
                         ? $options['PLAYLIST_NAMES'][$url->out()]
                         : str_replace('+', ' ', array_pop($path_parts));

            // Now that the title has been referenced using the orig
            // URL, remove the provider param if it is present
            if ($provider != null) {
                $url->remove_params(array('provider'));
            }

            // If there is an extension, remove it, but in the
            // case of an mp4 leave it as well as prepend it to
            // media path
            $matches = array();
            if (preg_match('/\.(' . join('|', $this->get_supported_extensions()) . ')$/i', $media_path, $matches)) {
                switch ($matches[1]) {
                    case "mp3" :
                        if (0 === preg_match("/^mp3:/", $media_path)) {
                            $media_path = substr($matches[1] . ':' . $media_path, 0, 0 - strlen($matches[0]));
                        }
                        break;
                    default :
                        $media_path = substr($media_path, 0, 0 - strlen($matches[0]));
                }
            }

            // Append the remainder (query string) of the original URL
            $query_str  = htmlspecialchars_decode($url->get_query_string(false));
            if (!empty($query_str)) {
                $media_path .= '?' . $query_str;
            }

            $clip_array_javascript .= "\n{$unique_id}[{$clip_index}] = { netConnectionUrl: '{$media_conx}', url: '{$media_path}', title: '{$media_title}', index: {$clip_index} };";
            $clip_index++;

        } // foreach

        // Emit JavaScript with the clip information and
        // the fallback div normally containing the link
        $playlist_open = $playlist_close = '';
        if (count($urls) > 1) {
            $playlist_open  = "<div class=\"filter_rtmp_wrapper\">\n";
            $playlist_close = "<div class=\"filter_rtmp_audio_playlist {$unique_id}\"></div>\n"
                            . "</div>\n";
        }

        return "\n" . html_writer::script($clip_array_javascript)
             . $playlist_open
             . html_writer::tag('div', core_media_player::PLACEHOLDER,
                   array('id' => $unique_id, 'class' => 'mediaplugin filter_rtmp_audio'))
             . $playlist_close;

    }


    public function get_supported_extensions()
    {
        return explode(',', self::EXTENSIONS);
    }


    public function get_rank()
    {
        return self::RANK;
    }

} // class filter_rtmp_player_audio



class filter_rtmp_player_link extends core_media_player
{

    public function embed($urls, $name, $width, $height, $options)
    {

        // If link is turned off, return empty.
        if (!empty($options[core_media::OPTION_NO_LINK])) {
            return '';
        }

        // Build up link content.
        $output = '';
        foreach ($urls as $url) {

            $title = core_media::get_filename($url);
            $printlink = html_writer::link($url, $title, array('class' => 'mediafallbacklink nomediaplugin'));
            if ($output) {
                // Where there are multiple available formats, there are fallback links
                // for all formats, separated by /.
                $output .= ' / ';
            }

            $output .= $printlink;

        }

        return $output;

    }


    public function list_supported_urls(array $urls, array $options = array())
    {
        return $urls;
    }


    public function is_enabled()
    {
        return true;
    }


    public function get_rank()
    {
        return 0;
    }

} // class filter_rtmp_player_link
