/**
 * Reporte PDF del panel de control.
 *
 * Se arma con los mismos datos que la pantalla ya tiene cargados, así el PDF
 * refleja exactamente lo que se está viendo: los filtros aplicados, la
 * agrupación elegida y el alcance del usuario que lo pide.
 *
 * No es una captura de pantalla: las tablas se dibujan como tablas, para que el
 * texto quede seleccionable y el documento sirva como anexo de un expediente.
 */
import { jsPDF } from 'jspdf';
import { applyPlugin } from 'jspdf-autotable';

// El plugin se registra explícitamente: así `doc.autoTable` existe igual de
// forma en el navegador y fuera de él, sin depender de cómo se resuelva el
// módulo.
applyPlugin(jsPDF);
import { fmtInt, fmtMoney } from '@/composables/useFormat';

const AZUL       = [31, 78, 121];
const AZUL_CLARO = [222, 230, 241];
const GRIS       = [120, 120, 120];

const MARGEN = 36; // ~1,25 cm

/** Encabezado y pie de cada página, con la numeración al final. */
function decorar(doc, titulo, subtitulo) {
    const paginas = doc.getNumberOfPages();
    const ancho   = doc.internal.pageSize.getWidth();
    const alto    = doc.internal.pageSize.getHeight();

    for (let i = 1; i <= paginas; i++) {
        doc.setPage(i);

        doc.setFillColor(...AZUL);
        doc.rect(0, 0, ancho, 46, 'F');

        doc.setTextColor(255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text('ATLAS', MARGEN, 22);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text(titulo, MARGEN, 36);

        doc.setFontSize(8);
        doc.text(subtitulo, ancho - MARGEN, 36, { align: 'right' });

        doc.setTextColor(...GRIS);
        doc.setFontSize(8);
        doc.text(`Página ${i} de ${paginas}`, ancho - MARGEN, alto - 18, { align: 'right' });
        doc.text('Documento generado por ATLAS', MARGEN, alto - 18);
    }
}

function titulo(doc, texto, y) {
    doc.setTextColor(...AZUL);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.text(texto, MARGEN, y);
    doc.setTextColor(0);
    return y + 6;
}

/** Tabla estándar del reporte. Devuelve la posición donde terminó. */
function tabla(doc, y, head, body, opciones = {}) {
    doc.autoTable({
        startY: y,
        head: [head],
        body,
        margin: { left: MARGEN, right: MARGEN, top: 62 },
        styles: { fontSize: 8, cellPadding: 3, overflow: 'linebreak' },
        headStyles: { fillColor: AZUL, textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 248, 248] },
        ...opciones,
    });
    return doc.lastAutoTable.finalY + 18;
}

/** Pares etiqueta/valor en dos columnas, para los indicadores. */
function tablaIndicadores(doc, y, pares) {
    const filas = [];
    for (let i = 0; i < pares.length; i += 2) {
        filas.push([
            pares[i][0], pares[i][1],
            pares[i + 1]?.[0] ?? '', pares[i + 1]?.[1] ?? '',
        ]);
    }

    doc.autoTable({
        startY: y,
        body: filas,
        margin: { left: MARGEN, right: MARGEN, top: 62 },
        styles: { fontSize: 8, cellPadding: 4 },
        columnStyles: {
            0: { fontStyle: 'bold', fillColor: AZUL_CLARO },
            1: { halign: 'right' },
            2: { fontStyle: 'bold', fillColor: AZUL_CLARO },
            3: { halign: 'right' },
        },
        theme: 'grid',
    });
    return doc.lastAutoTable.finalY + 18;
}

/**
 * Distribución con barra: la última columna se dibuja a mano, proporcional al
 * mayor valor de la tabla.
 */
function tablaDistribucion(doc, y, encabezado, filas) {
    if (!filas.length) return y;

    const maximo = Math.max(...filas.map(f => Math.abs(f.value)), 1);

    y = titulo(doc, encabezado, y);
    return tabla(doc, y,
        ['', 'Expedientes', 'Saldo', ''],
        filas.map(f => [f.label, f.extra, fmtMoney(f.value), '']),
        {
            columnStyles: {
                0: { cellWidth: 150 },
                1: { halign: 'right', cellWidth: 70 },
                2: { halign: 'right', cellWidth: 90 },
                3: { cellWidth: 'auto' },
            },
            didDrawCell: (data) => {
                if (data.section !== 'body' || data.column.index !== 3) return;
                const valor = filas[data.row.index].value;
                if (!valor) return;

                const ancho = (Math.abs(valor) / maximo) * (data.cell.width - 8);
                doc.setFillColor(...(valor < 0 ? [192, 80, 77] : AZUL));
                doc.rect(data.cell.x + 4, data.cell.y + 4, ancho, data.cell.height - 8, 'F');
            },
        });
}

/**
 * Genera y descarga el PDF.
 *
 * @param {object} datos  lo que el panel ya tiene cargado
 */
export function generarPanelPdf(datos) {
    const {
        ind, calc, saldos, dGer, dUvt, acciones, venc, ranks,
        filtrosAplicados = [], alcance = '', usuario = '',
        tituloSaldos = 'Estructura', accionLabels = {},
    } = datos;

    const doc = new jsPDF({ unit: 'pt', format: 'a4' });
    const emitido = new Date().toLocaleString('es-AR');
    let y = 70;

    // ---- Portada de la primera página: de dónde salen estos números ----
    y = titulo(doc, 'Panel de control', y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(...GRIS);
    doc.text(`Emitido: ${emitido}${usuario ? ` · ${usuario}` : ''}`, MARGEN, y + 8);
    doc.text(`Alcance: ${alcance || 'Toda la organización'}`, MARGEN, y + 20);
    doc.text(`Filtros: ${filtrosAplicados.length ? filtrosAplicados.join(' · ') : 'sin filtros'}`,
             MARGEN, y + 32);
    doc.setTextColor(0);
    y += 52;

    // ---- Indicadores principales ----
    const moneda = ind?.montos?.moneda_base || 'Peso';
    y = titulo(doc, 'Indicadores principales', y);
    y = tablaIndicadores(doc, y, [
        ['Expedientes',              fmtInt(ind?.totales?.contratos)],
        ['En firma',                 fmtInt(ind?.totales?.en_firma)],
        ['En ejecución',             fmtInt(ind?.totales?.en_ejecucion)],
        ['Finalizados',              fmtInt(ind?.totales?.finalizados)],
        ['Vencidos',                 fmtInt(ind?.totales?.vencidos)],
        ['Con atraso',               fmtInt(ind?.totales?.con_atraso)],
        [`Saldo inicial (${moneda})`, fmtMoney(ind?.montos?.saldo_inicial_total)],
        [`Saldo (${moneda})`,         fmtMoney(ind?.montos?.saldo_total)],
        ['Ejecutado ingresos',       fmtMoney(ind?.montos?.ejecutado_ingresos_total)],
        ['Ejecutado gastos',         fmtMoney(ind?.montos?.ejecutado_gastos_total)],
        ['Beneficio',                fmtMoney(ind?.montos?.beneficio_total)],
        ['', ''],
    ]);

    // ---- Indicadores calculados ----
    const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
    y = titulo(doc, 'Indicadores calculados', y);
    y = tablaIndicadores(doc, y, [
        ['Días promedio de firma',     calc?.dias_firma_promedio ?? '—'],
        ['Días promedio de ejecución', calc?.dias_ejecucion_promedio ?? '—'],
        ['% Finalizados en término',   pct(calc?.porcentaje_finalizados_en_termino)],
        ['% Vencidos sin cierre',      pct(calc?.porcentaje_vencidos_sin_cierre)],
        ['% Ejecución económica',      pct(calc?.porcentaje_ejecucion_economica)],
        ['', ''],
    ]);

    // ---- Saldos ----
    doc.addPage();
    y = 70;
    y = titulo(doc, `Saldos · ${tituloSaldos}`, y);

    const filasSaldos = (saldos?.filas || []).map(f => [
        f.alcance === 'acumulado'
            ? '   '.repeat(f.nivel) + f.etiqueta
            : '   '.repeat(f.nivel) + '    sólo lo propio',
        fmtInt(f.contratos),
        fmtMoney(f.saldo_inicial),
        fmtMoney(f.ejecutado_ingresos),
        fmtMoney(f.ejecutado_gastos),
        fmtMoney(f.saldo),
    ]);
    filasSaldos.push([
        `Total (${saldos?.moneda_base || moneda})`,
        fmtInt(saldos?.totales?.contratos),
        fmtMoney(saldos?.totales?.saldo_inicial),
        fmtMoney(saldos?.totales?.ejecutado_ingresos),
        fmtMoney(saldos?.totales?.ejecutado_gastos),
        fmtMoney(saldos?.totales?.saldo),
    ]);

    y = tabla(doc, y,
        ['Estructura', 'Expedientes', 'Saldo inicial', 'Ingresos', 'Gastos', 'Saldo'],
        filasSaldos,
        {
            columnStyles: {
                0: { cellWidth: 150 },
                1: { halign: 'right' },
                2: { halign: 'right' },
                3: { halign: 'right' },
                4: { halign: 'right' },
                5: { halign: 'right', fontStyle: 'bold' },
            },
            // La fila del total y las acumuladas se destacan.
            didParseCell: (data) => {
                if (data.section !== 'body') return;
                const fila = saldos?.filas?.[data.row.index];
                if (!fila) { data.cell.styles.fontStyle = 'bold'; return; }
                if (fila.alcance === 'acumulado') data.cell.styles.fontStyle = 'bold';
                if (fila.nivel === 0) data.cell.styles.fillColor = AZUL_CLARO;
            },
        });

    // ---- Distribuciones ----
    doc.addPage();
    y = 70;
    y = titulo(doc, 'Distribución', y) + 12;
    y = tablaDistribucion(doc, y, 'Por Gerencia de Área', dGer?.gerencias_area?.map(mapear) || []);
    y = tablaDistribucion(doc, y, 'Por Gerencia',         dGer?.sectores?.map(mapear) || []);
    y = tablaDistribucion(doc, y, 'Por UVT',              dUvt?.contratos?.map(mapearUvt) || []);

    // ---- Movimientos por acción ----
    if (acciones?.movimientos?.length) {
        y = titulo(doc, 'Ejecución por acción', y);
        y = tabla(doc, y,
            ['Acción', 'Tipo', 'Movimientos', 'Total'],
            acciones.movimientos.map(m => [
                accionLabels[m.accion] || m.accion,
                m.tipo,
                fmtInt(m.cantidad),
                fmtMoney(m.total),
            ]),
            { columnStyles: { 2: { halign: 'right' }, 3: { halign: 'right' } } });
    }

    // ---- Vencimientos ----
    y = titulo(doc, 'Próximos vencimientos', y);
    y = tablaIndicadores(doc, y, [
        ['Vencidos',        fmtInt(venc?.vencidos)],
        ['Vencen en 30 días', fmtInt(venc?.dias_30)],
        ['Vencen en 60 días', fmtInt(venc?.dias_60)],
        ['Vencen en 90 días', fmtInt(venc?.dias_90)],
    ]);

    // ---- Rankings ----
    if (ranks?.gerencias_area_por_cantidad?.length) {
        y = titulo(doc, 'Gerencias de Área con más expedientes', y);
        y = tabla(doc, y, ['Gerencia de Área', 'Cantidad'],
            ranks.gerencias_area_por_cantidad.map(r => [r.gerencia_area || '—', fmtInt(r.cantidad)]),
            { columnStyles: { 1: { halign: 'right' } } });
    }

    if (ranks?.uvt_por_monto?.length) {
        y = titulo(doc, 'UVT por monto', y);
        tabla(doc, y, ['UVT', 'Saldo inicial', 'Saldo'],
            ranks.uvt_por_monto.map(r => [
                r.nombre ? `${r.siglas} — ${r.nombre}` : r.siglas,
                fmtMoney(r.saldo_inicial),
                fmtMoney(r.saldo),
            ]),
            { columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' } } });
    }

    decorar(doc, 'Panel de control', emitido);

    const fecha = new Date().toISOString().slice(0, 10);
    doc.save(`atlas-panel-${fecha}.pdf`);
}

function mapear(r) {
    return { label: r.nombre || '—', value: Number(r.saldo) || 0, extra: fmtInt(r.cantidad) };
}

function mapearUvt(r) {
    return { label: r.siglas || '—', value: Number(r.saldo) || 0, extra: fmtInt(r.cantidad) };
}
