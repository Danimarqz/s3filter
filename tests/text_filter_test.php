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
}
