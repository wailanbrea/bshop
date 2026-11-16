import { clearPending, readPending, savePending } from './pendingRequests';

type Item = { productId:number; name:string };
const key = (actorId:number) => `bshop:product-photos:${actorId}`;
export function photoRecoveries(actorId:number):Item[] {
    const value=readPending(key(actorId));
    return Array.isArray(value?.items)?value.items.filter((item:unknown):item is Item=>!!item&&typeof item==='object'&&Number.isInteger((item as Item).productId)&&typeof (item as Item).name==='string'):[];
}
export function rememberPhotos(actorId:number,productId:number,name:string):void {
    const items=photoRecoveries(actorId).filter(item=>item.productId!==productId);items.push({productId,name});savePending(key(actorId),{items});
}
export function forgetPhotos(actorId:number,productId:number):void {
    const items=photoRecoveries(actorId).filter(item=>item.productId!==productId);if(items.length)savePending(key(actorId),{items});else clearPending(key(actorId));
}
