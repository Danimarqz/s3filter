<?php
// Tests de la plantilla del watermark: qué campos se sustituyen, cuáles se
// rechazan y qué colores se aceptan. Lo que se fija aquí es sobre todo el
// límite de confianza: la plantilla la escribe un administrador, pero los
// valores salen del perfil de cada alumno y acaban en un <script>, y el
// color acaba dentro de un bloque <style>.

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \filter_impronta\watermark
 * @covers \filter_impronta\token
 */
class watermark_test extends \advanced_testcase {

    /** Plantilla con llaves, que es la forma documentada. */
    public function test_label_con_llaves(): void {
        $this->resetAfterTest();
        set_config('watermarktemplate', '{firstname} - {idnumber}', 'filter_impronta');

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Juan',
            'lastname' => 'Pérez',
            'idnumber' => '12345678A',
        ]);

        $this->assertSame('Juan - 12345678A', watermark::label($user));
    }

    /**
     * Las instalaciones anteriores a las llaves guardaron "name - dni" en el
     * ajuste. Si dejaran de sustituirse, el watermark de esos sitios pasaría
     * a ser el texto literal "name - dni" sin que nadie se entere.
     */
    public function test_label_formato_antiguo_sin_llaves(): void {
        $this->resetAfterTest();
        set_config('watermarktemplate', 'name - dni', 'filter_impronta');

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ana',
            'lastname' => 'Gómez',
            'idnumber' => 'X1234567L',
        ]);

        $this->assertSame(fullname($user) . ' - X1234567L', watermark::label($user));
    }

    /** Un campo permitido pero vacío se sustituye por nada, no se deja literal. */
    public function test_campo_vacio_se_sustituye_por_nada(): void {
        $this->resetAfterTest();
        set_config('watermarktemplate', '{firstname}{idnumber}', 'filter_impronta');

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'idnumber' => '']);

        $this->assertSame('Lucía', watermark::label($user));
    }

    /** Un token que no existe se deja literal, para que se vea la errata. */
    public function test_token_desconocido_se_deja_literal(): void {
        $this->resetAfterTest();
        set_config('watermarktemplate', '{firstname} {noexiste}', 'filter_impronta');

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Marta']);

        $this->assertSame('Marta {noexiste}', watermark::label($user));
    }

    /**
     * Lo importante de la lista blanca: $USER lleva también el hash de la
     * contraseña y el sesskey, y el watermark se pinta en el navegador del
     * alumno. Un token fuera de la lista no debe resolverse jamás.
     */
    public function test_campos_sensibles_no_se_resuelven(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $user->password = 'hash-que-no-debe-salir';
        $user->sesskey = 'sesskey-que-no-debe-salir';
        $user->secret = 'secreto-que-no-debe-salir';

        foreach (['password', 'sesskey', 'secret', 'id'] as $token) {
            $this->assertNull(
                watermark::field($user, $token),
                "El token '$token' no debería resolverse"
            );
        }
    }

    public function test_campos_permitidos_y_de_perfil_se_resuelven(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Eva', 'city' => 'Bilbao']);
        $user->profile_field_dni = 'B98765432';

        $this->assertSame('Eva', watermark::field($user, 'firstname'));
        $this->assertSame('Bilbao', watermark::field($user, 'city'));
        $this->assertSame('B98765432', watermark::field($user, 'profile_field_dni'));
        // Alias heredados.
        $this->assertSame(fullname($user), watermark::field($user, 'name'));
    }

    /**
     * El token atado a usuario es lo que sustituye a la cookie de sesión en
     * el camino de la app, así que su integridad es lo único que separa a un
     * alumno de reproducir como otro.
     */
    public function test_token_atado_a_usuario(): void {
        $this->resetAfterTest();
        set_config('secretkey', 'un-secreto-de-pruebas', 'filter_impronta');
        set_config('bindip', 0, 'filter_impronta');

        $path = 'Materia/Clase';
        $expires = time() + 3600;
        $ip = '203.0.113.7';

        $token = token::generate($path, $expires, 5, $ip, 42);

        // Sirve para el usuario que se firmó...
        $this->assertTrue(token::validate($path, $token, $expires, 5, $ip, 42));
        // ...y para nadie más: cambiar la u de la URL invalida el HMAC.
        $this->assertFalse(token::validate($path, $token, $expires, 5, $ip, 43));
        // Ni permite quitar la atadura para colarse como anónimo.
        $this->assertFalse(token::validate($path, $token, $expires, 5, $ip, 0));
        // Ni reutilizarlo en otra clase o en otro curso.
        $this->assertFalse(token::validate('Materia/Otra', $token, $expires, 5, $ip, 42));
        $this->assertFalse(token::validate($path, $token, $expires, 6, $ip, 42));
        // Caducado, no.
        $this->assertFalse(token::validate($path, $token, time() - 1, 5, $ip, 42));
    }

    /**
     * Los tokens ya emitidos viven horas: si el despliegue los invalidara,
     * cortaría la reproducción a quien esté viendo una clase en ese momento.
     */
    public function test_token_sin_usuario_sigue_validando(): void {
        $this->resetAfterTest();
        set_config('secretkey', 'un-secreto-de-pruebas', 'filter_impronta');
        set_config('bindip', 0, 'filter_impronta');

        $expires = time() + 3600;
        $viejo = token::generate('Materia/Clase', $expires, 5, '203.0.113.7');

        $this->assertTrue(token::validate('Materia/Clase', $viejo, $expires, 5, '203.0.113.7'));
    }

    /**
     * El color se interpola en un <style>: un valor libre podría cerrar la
     * regla e inyectar CSS. Solo hexadecimal; lo demás cae al blanco.
     */
    public function test_color_solo_acepta_hexadecimal(): void {
        $this->resetAfterTest();

        $validos = ['#fff', '#ffffff', '#FF0000', '#12345678'];
        foreach ($validos as $color) {
            set_config('watermarkcolor', $color, 'filter_impronta');
            $this->assertSame($color, watermark::color());
        }

        $invalidos = [
            'red',
            'rgb(255,0,0)',
            '#fff; } body { display: none',
            '</style><script>alert(1)</script>',
            '',
        ];
        foreach ($invalidos as $color) {
            set_config('watermarkcolor', $color, 'filter_impronta');
            $this->assertSame('#ffffff', watermark::color());
        }
    }
}
