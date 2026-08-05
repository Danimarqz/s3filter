<?php
$string['pluginname'] = 'S3 Video filter';
$string['notsupported'] = 'Your browser does not support the video tag.';
$string['openvideo'] = 'Open video';
$string['openvideoinfo'] = 'The video will open in your browser.';
$string['tokeninvalid'] = 'This secure link has expired. Please open the video again from the Moodle app.';
$string['sessionconflict'] = 'You are currently logged in as {$a}. Log out from this browser before trying with a different user.';
$string['logoutandretry'] = 'Log out and try again';
$string['reopenthroughapp'] = 'Open the video from Moodle again to get a fresh link.';
$string['missingfilename'] = 'The video identifier is missing. Please open this resource again from Moodle.';
$string['manualenrolrequired'] = 'Your account must have an active manual enrolment to access this video.';

// Settings.
$string['settings'] = 'Backend and watermark';
$string['backendheading'] = 'Reelo backend';
$string['backenddesc'] = 'The plugin is tied to the Reelo deployment at {$a}. This URL is fixed and cannot be changed here.';
$string['apikey'] = 'Reelo API key';
$string['apikeydesc'] = 'The tenant API token. It comes from the Reelo root console (tenant detail) and can be rotated there.';
$string['secretkey'] = 'Local signing secret';
$string['secretkeydesc'] = 'A secret used to sign internal tokens between the filter and the playlist/embed endpoints. Keep it random and private.';
$string['watermarkheading'] = 'Watermark';
$string['watermarkdesc'] = 'The watermark is a per-user overlay (\'{name}\' full name, \'{dni}\' idnumber, or profile_field_XXX for a custom profile field).';
$string['watermarktemplate'] = 'Watermark template';
$string['watermarktemplatedesc'] = 'Tokens replaced per user: "name", "dni", or "profile_field_XXX". Example: "name - profile_field_dni".';
$string['accessheading'] = 'Course access';
$string['accessdesc'] = 'By default a video is only playable by users enrolled in the course where it is embedded.';
$string['requirecourse'] = 'Require course enrolment';
$string['requirecoursedesc'] = 'Block playback when the video is not embedded inside a course (or the user is not enrolled).';
$string['bindip'] = 'Bind tokens to IP';
$string['bindipdesc'] = 'Add the requester IP to the internal token payload for extra protection.';
$string['tokenttl'] = 'Internal token TTL (seconds)';
$string['tokenttldesc'] = 'How long a playlist token stays valid. Must cover the whole viewing session.';
$string['nocoursecontext'] = 'This video is only available inside a course.';
$string['notenrolled'] = 'You are not enrolled in the course where this video is embedded.';
$string['servicedenied'] = 'This site is not authorised to access the video service. Contact your site administrator.';
$string['servicedown'] = 'The video service is temporarily unavailable. Try again in a moment.';