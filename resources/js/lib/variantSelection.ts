import type { SalesVariant } from '../sales';

const name = (value:string) => value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('es');
export function attribute(variant:SalesVariant, key:'color'|'talla'):string|undefined {
    return variant.attributes.find(item => name(item.name) === key)?.value;
}
export function supportsColorSize(variants:SalesVariant[]):boolean {
    return variants.length>0 && variants.every(item => item.attributes.length===2 && !!attribute(item,'color') && !!attribute(item,'talla'));
}
export function available(variant:SalesVariant):boolean {
    if (variant.available===null) return true;
    return /^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/.test(variant.available) && !/^0(?:\.0+)?$/.test(variant.available);
}
