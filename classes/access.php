<?php
/**
 * Capa 2 del control de acceso: el curso donde se incrustó el vídeo.
 *
 * Impronta no puede hacer esta comprobación —las matrículas son de Moodle— y la
 * identidad del curso sale del contexto de render del filtro, así que no es
 * algo que el navegador pueda cambiar.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Curso del contexto y matrícula del alumno.
 */
class access {

    /**
     * Curso al que pertenece un contexto de filtro.
     *
     * @param \context|null $context
     * @return int id del curso, o 0 si el contexto no está dentro de uno
     */
    public static function courseid_from_context($context): int {
        if (!$context instanceof \context) {
            return 0;
        }
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return 0;
        }
        // SITEID es la portada, que dejaría la comprobación en nada.
        if ((int) $coursecontext->instanceid === (int) SITEID) {
            return 0;
        }
        return (int) $coursecontext->instanceid;
    }

    /**
     * Si el usuario puede ver contenido de un curso.
     *
     * La matrícula activa es la regla para los alumnos. Los gestores y
     * profesores que pueden ver un curso sin estar matriculados pasan por la
     * comprobación de capacidad, para que un profesor pueda revisar su propio
     * material.
     *
     * @param int $courseid
     * @param int $userid usuario firmado en el token (0 = el de la sesión)
     * @return bool
     */
    public static function can_view_course(int $courseid, int $userid = 0): bool {
        global $USER;

        if ($courseid <= 0) {
            return false;
        }

        // $userid > 0 llega del token firmado (camino de la app, sin cookie de
        // sesión): la identidad la prueba el HMAC, no isloggedin(). Con 0 se
        // comprueba el usuario de la sesión actual, que es el camino de siempre.
        if ($userid <= 0) {
            if (!isloggedin() || isguestuser()) {
                return false;
            }
            $userid = $USER->id ?? 0;
            if ($userid <= 0) {
                return false;
            }
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // La matrícula se recomprueba en cada petición aunque el token la
        // acreditara al emitirse: entre la emisión y la reproducción pueden pasar
        // horas, y una baja del curso debe cortar el acceso sin esperar a que
        // caduque el token.
        if (is_enrolled($context, $userid, '', true)) {
            return true;
        }

        return has_capability('moodle/course:view', $context, $userid);
    }
}
