<?php
/**
 * Legacy tag compatibility tests.
 *
 * @package filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/** @covers \filter_impronta\text_filter */
class text_filter_test extends \advanced_testcase {

    public function test_legacy_s3_tags_use_the_impronta_player(): void {
        $this->resetAfterTest();
        set_config('secretkey', 'test-secret', 'filter_impronta');
        set_config('requirecourse', 0, 'filter_impronta');

        $filter = new text_filter(\context_system::instance(), []);

        $video = $filter->filter('[s3:Materia/Clase]');
        $audio = $filter->filter('[s3audio:Materia/Audio]');

        $this->assertStringNotContainsString('[s3:', $video);
        $this->assertStringContainsString('/filter/impronta/playlist.php?', $video);
        $this->assertStringContainsString('f=Materia%2FClase', $video);
        $this->assertStringNotContainsString('[s3audio:', $audio);
        $this->assertStringContainsString('f=Materia%2FAudio', $audio);
        $this->assertStringContainsString('a=1', $audio);
    }

    public function test_mobile_player_keeps_playback_id_in_every_signed_endpoint(): void {
        $this->resetAfterTest();
        set_config('secretkey', 'test-secret', 'filter_impronta');
        set_config('requirecourse', 0, 'filter_impronta');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $_SERVER['HTTP_USER_AGENT'] = 'MoodleMobile';

        $filter = new text_filter(\context_system::instance(), []);
        $video = $filter->filter('[s3:Materia/Clase]');

        $this->assertMatchesRegularExpression('#playlist\\.php\\?[^\"]*p=r[0-9a-f]+#', $video);
        $this->assertMatchesRegularExpression('#events\\.php\\?[^\"]*p=r[0-9a-f]+#', $video);
        $this->assertMatchesRegularExpression('#heartbeat\\.php\\?[^\"]*p=r[0-9a-f]+#', $video);

        $expires = time() + 60;
        $playbackid = 'r0123456789abcdef';
        $token = token::generate('Materia/Clase', $expires, 0, request::ip(), (int) $user->id,
            true, '', $playbackid);
        $isapp = false;
        $this->assertNull(token::authorize('Materia/Clase', $token, $expires, 0, (int) $user->id,
            $isapp, '', $playbackid));
        $this->assertTrue($isapp);
    }
}
