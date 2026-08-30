<?php
$string['pluginname'] = 'Impronta';
// A filter is named by 'filtername', not by 'pluginname': it titles both the
// manage-filters row and the settings page. Missing, Moodle prints the literal
// "[filtername,filter_impronta]" instead.
$string['filtername'] = 'Impronta';
$string['notsupported'] = 'Your browser does not support the video tag.';
$string['openvideo'] = 'Open video';
$string['openvideoinfo'] = 'The video will open in your browser.';
$string['tokeninvalid'] = 'This secure link has expired. Please open the video again from the Moodle app.';
$string['sessionconflict'] = 'You are currently logged in as {$a}. Log out from this browser before trying with a different user.';
$string['logoutandretry'] = 'Log out and try again';
$string['reopenthroughapp'] = 'Open the video from Moodle again to get a fresh link.';
$string['sessionexpired'] = 'The playback session has expired. Open the video again from Moodle.';
$string['accessrevoked'] = 'Access revoked. Please contact your academy.';
$string['sessionevicted'] = 'This class was opened on another device. Reload the page to continue here.';
$string['missingfilename'] = 'The video identifier is missing. Please open this resource again from Moodle.';
$string['manualenrolrequired'] = 'Your account must have an active manual enrolment to access this video.';

// Settings.
$string['backendheading'] = 'Impronta backend';
$string['backenddesc'] = 'The plugin is tied to the Impronta deployment at {$a}. This URL is fixed and cannot be changed here.';
$string['apikey'] = 'Impronta API key';
$string['apikeydesc'] = 'The tenant API token. It comes from the Impronta root console (tenant detail) and can be rotated there.';
$string['secretkey'] = 'Local signing secret';
$string['secretkeydesc'] = 'A secret used to sign internal tokens between the filter and the playlist/embed endpoints. Keep it random and private.';
$string['registersite'] = 'Register this Moodle site';
$string['registersitedesc'] = 'After saving the API key and secret, register this Moodle origin with Impronta so iframe shares can be restricted to this site.';
$string['registersitebutton'] = 'Register Moodle site';
$string['registerneedscredentials'] = 'Save both the API key and local signing secret above to enable registration.';
$string['registersuccess'] = 'This Moodle site is now registered with Impronta.';
$string['registermediabase'] = 'Tenant media: {$a} (saved automatically when registering the site; re-register if the tenant changes CDN)';
$string['registerfailure'] = 'The Moodle site could not be registered. Check the API key and connectivity.';
$string['scormguest'] = 'Guest users cannot play shared videos. Please log in to Moodle.';
$string['scormsession'] = 'This shared video link belongs to a different Moodle session. Open it again from your account.';
$string['scormgroupinvalid'] = 'The authorization group link is invalid.';
$string['watermarkheading'] = 'Watermark';
$string['watermarkdesc'] = 'The watermark is a text overlay on the video, different for each learner, identifying whoever is watching.';
$string['watermarktemplate'] = 'Watermark template';
$string['watermarktemplatedesc'] = 'Fields in braces are replaced with each learner\'s data; everything else is kept literally. Example: <code>{firstname} - {profile_field_dni}</code>.<br />Available: <code>{firstname}</code>, <code>{lastname}</code>, <code>{fullname}</code>, <code>{email}</code>, <code>{username}</code>, <code>{idnumber}</code>, <code>{alternatename}</code>, <code>{middlename}</code>, <code>{city}</code>, <code>{country}</code>, <code>{institution}</code>, <code>{department}</code>, <code>{phone1}</code>, <code>{phone2}</code>, and <code>{profile_field_XXX}</code> for any custom profile field.<br />A field the learner left empty is replaced with nothing (the separator around it stays); a misspelt field name shows up literally, which is how you spot the typo.';
$string['mobileusers'] = 'In-app player (user ids)';
$string['mobileusersdesc'] = 'Comma-separated user ids that receive the player inside the Moodle app. <strong>Empty = everyone</strong>, which is the normal value; with ids, only those users.<br />Note: the mobile addon is served to every app connected to this site, so a fault here does not only affect whoever opens a video — it can leave the app unable to load any course for everyone. That is why this list exists: to narrow a change down to a few users without exposing learners.';
$string['watermarkcolor'] = 'Watermark colour';
$string['watermarkcolordesc'] = 'Colour of the overlay text. The white default reads best over most video: the closer the colour is to the footage, the less legible the watermark is if you ever need it as evidence.';
$string['accessheading'] = 'Course access';
$string['accessdesc'] = 'By default a video is only playable by users enrolled in the course where it is embedded.';
$string['requirecourse'] = 'Require course enrolment';
$string['requirecoursedesc'] = 'Block playback when the video is not embedded inside a course (or the user is not enrolled).';
$string['bindip'] = 'Bind tokens to IP';
$string['bindipdesc'] = 'Add the requester IP to the internal token payload for extra protection.';
$string['tokenttl'] = 'Internal token TTL (seconds)';
$string['tokenttldesc'] = 'How long a playlist token stays valid. Besides covering the whole viewing session, it must OUTLIVE the Impronta CloudFront signature (TTL = class duration + 30 min, 6 h ceiling): the 403 recovery reloads the playlist with this same token, and if the token expires before the signature the retry dies with a 403. The default (7 h = 25200 s) sits above the signature ceiling; do not set it below that.';
$string['nocoursecontext'] = 'This video is only available inside a course.';
$string['notenrolled'] = 'You are not enrolled in the course where this video is embedded.';
$string['servicedenied'] = 'This site is not authorised to access the video service. Contact your site administrator.';
$string['servicedown'] = 'The video service is temporarily unavailable. Try again in a moment.';

// Who is who: turns an Impronta pseudonym back into a Moodle user.
$string['whotitle'] = 'Impronta: who is this learner?';
$string['whointro'] = 'Impronta never sees the names of your learners: it stores an identifier only this Moodle can undo. Paste the identifiers you see in an alert or in the dashboard —one per line, comma-separated, or the whole email pasted in— and we will tell you who they are.';
$string['wholookup'] = 'Look up';
$string['whocolid'] = 'Identifier';
$string['whocoluser'] = 'Learner';
$string['whocolemail'] = 'Email';
$string['whounknown'] = 'No match (deleted account, or the signing key changed?)';
$string['whononefound'] = 'No identifier recognised. They are 24 hexadecimal characters, like 803cc8a9bc6813259f0383d3.';
$string['whonosecret'] = 'No signing key is configured, so nothing can be resolved. Set it in the plugin settings.';

// Settings info block.
$string['aboutheading'] = 'About Impronta';
$string['aboutdesc'] = '<p>This plugin connects your Moodle to <a href="https://impronta.video/" target="_blank" rel="noopener">Impronta</a>: every learner sees the class with their own name and ID on screen, links expire within minutes, and every class request is logged.</p><p><a href="https://impronta.video/moodle" target="_blank" rel="noopener">How the integration works</a> · <a href="https://impronta.video/seguridad" target="_blank" rel="noopener">What it protects, and what it does not</a> · <a href="https://impronta.video/precios" target="_blank" rel="noopener">Pricing</a></p><p><a href="{$a}">Who is this learner?</a> — turns the identifiers in the alerts into real names. The mapping never leaves this server.</p>';
