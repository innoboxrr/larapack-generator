import { Fragment } from 'react'
import { Link } from 'react-router-dom'
import { classFor } from 'innoboxrr-form-core'

/**
 * Las migas de pan del modulo.
 *
 * La rama React no tenia migas en absoluto: las vistas Vue usaban un
 * <BreadcrumbsComponent> que no estaba definido en ningun paquete del
 * ecosistema —la aplicacion anfitriona tenia que registrarlo por su cuenta— y
 * en React no habia equivalente. Al generarlo dentro del modulo, las dos
 * ramas pintan lo mismo y ninguna depende de nada que no declare.
 *
 * @param {{ pages: { link: string, title: string }[] }} props
 */
export default function Breadcrumbs({ pages }) {
    return (
        <nav className={classFor('breadcrumb')} aria-label="Breadcrumb">

            {pages.map((page, index) => (
                <Fragment key={page.link ?? page.title}>

                    {index > 0 && (
                        <span className={classFor('breadcrumbSeparator')} aria-hidden="true">/</span>
                    )}

                    {/* El ultimo es donde esta el usuario: no es un enlace, y
                        se anuncia como la pagina actual. */}
                    {index === pages.length - 1 ? (
                        <span className={classFor('breadcrumbCurrent')} aria-current="page">
                            {page.title}
                        </span>
                    ) : (
                        <Link to={page.link} className={classFor('breadcrumbLink')}>
                            {page.title}
                        </Link>
                    )}

                </Fragment>
            ))}

        </nav>
    )
}
