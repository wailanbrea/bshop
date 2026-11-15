export const creditReversalText={
    title:'Revertir aplicación de crédito',action:'Revertir aplicación',actionAria:(id:number)=>`Revertir aplicación de crédito #${id}`,
    description:'Corrige una aplicación registrada por error. Reabre la deuda del destino y devuelve el saldo a las mismas liberaciones de origen. No registra dinero nuevo ni mueve mercancía.',
    original:(id:number)=>`Aplicación #${id}`,payment:(id:number)=>`Pago original #${id}`,amount:'Crédito a restaurar',pending:'Pendiente actual del destino',after:'Pendiente después de revertir',
    reason:'Motivo de la reversión de crédito',confirm:'Confirmar reversión de crédito',close:'Volver al historial de crédito',refresh:'Actualizar revisión de reversión',loading:'Revisando aplicación de crédito…',
    success:'Aplicación revertida. Se restauró el crédito de origen y se reabrió la deuda del destino.',recovered:'Reversión de crédito recuperada sin repetir movimientos.',
    historyReversed:'Aplicación revertida',blocked:'La aplicación ya fue revertida, tiene una devolución pendiente, fue liberada posteriormente o requiere conciliación adicional.',
    pendingMessage:'Reversión sin respuesta confirmada. Se conservan el mismo UUID y motivo.',check:'Comprobar reversión de crédito',retry:'Reintentar reversión de crédito',notConfirmed:'La reversión no está confirmada. Puedes reintentar los mismos datos.',journalError:'No se pudo guardar la recuperación. No se envió la reversión.',
};
