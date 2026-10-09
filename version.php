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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Version details for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_onelife';
$plugin->version = 2026101000;
// Moodle 4.5 LTS is the supported floor (see PRD section 3). 4.1 is deliberately not supported.
$plugin->requires = 2024100700;
// Declared as well as required, so a site on an untested release is warned rather than
// left to find out. 5.3 became the current LTS on 5 October 2026.
$plugin->supported = [405, 503];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.1';
