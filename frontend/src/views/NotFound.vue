<template>
    <div class="empty-state" style="padding-top:80px;">
        <h2>404 — Página no encontrada</h2>
        <p>La ruta solicitada no existe.</p>
        <router-link :to="{ name: 'panel' }">Volver al Panel</router-link>
    </div>
</template>
