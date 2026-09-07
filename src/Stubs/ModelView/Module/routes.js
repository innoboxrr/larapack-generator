/**
 * Rutas del modulo, agregadas desde los modelos.
 *
 * El descubrimiento es por glob de Vite: al generar un modelo nuevo aparece
 * aqui solo, sin tener que enumerarlo. Se hace en modo `eager` porque los
 * archivos de rutas son pequenos y el router los necesita al arrancar.
 */

const modules = import.meta.glob('../models/*/routes/index.js', { eager: true })

export default Object.values(modules).flatMap((module) => module.default ?? [])
