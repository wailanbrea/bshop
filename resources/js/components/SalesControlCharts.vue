<script setup lang="ts">
import {computed,ref} from 'vue';
import {use,type EChartsCoreOption} from 'echarts/core';
import {LineChart,BarChart,PieChart} from 'echarts/charts';
import {GridComponent,TooltipComponent,AriaComponent,DataZoomComponent} from 'echarts/components';
import {SVGRenderer} from 'echarts/renderers';
import VChart from 'vue-echarts';
import {amount} from '../catalog';
import {decimal,units} from '../sales';
import type {ControlDay,ControlCash} from '../salesControl';
import {salesControlText as t} from '../locales/es/salesControl';
use([SVGRenderer,LineChart,BarChart,PieChart,GridComponent,TooltipComponent,AriaComponent,DataZoomComponent]);
const props=defineProps<{daily:ControlDay[];cash:ControlCash[];currencies:string[];from:string;to:string}>();
const selected=ref(props.currencies[0]||'');const mode=ref<'line'|'bar'>('line');
const currency=computed(()=>props.currencies.includes(selected.value)?selected.value:props.currencies[0]||'');
const cash=computed(()=>props.cash.find(c=>c.currency===currency.value));
const colors=['#7755cc','#39a9a4','#e8a45e','#697ad4','#bf75ad'];
const days=computed(()=>{
    const existing=new Map(props.daily.filter(d=>d.currency===currency.value).map(d=>[d.date,d]));const result:ControlDay[]=[];
    const cursor=new Date(`${props.from}T00:00:00Z`);const end=new Date(`${props.to}T00:00:00Z`);
    while(cursor<=end&&result.length<366){const date=cursor.toISOString().slice(0,10);result.push(existing.get(date)||{date,currency:currency.value,orders:0,net_total:'0.0000'});cursor.setUTCDate(cursor.getUTCDate()+1);}return result;
});
const maximum=computed(()=>days.value.reduce((max,d)=>{const v=units(d.net_total)||0n;return v>max?v:max;},0n));
const hasDays=computed(()=>days.value.some(d=>d.orders>0));
const methods=computed(()=>cash.value?.methods.filter(m=>(units(m.amount)||0n)>0n)||[]);
const methodTotal=computed(()=>methods.value.reduce((sum,m)=>sum+(units(m.amount)||0n),0n));
/** Only bounded, dimensionless geometry enters ECharts; all money stays decimal/BigInt. */
const ratio=(value:string,max:bigint)=>max?Number(((units(value)||0n)*10000n)/max):0;
const date=(value:string)=>new Intl.DateTimeFormat(t.locale,{day:'numeric',month:'short',timeZone:'UTC'}).format(new Date(`${value}T00:00:00Z`));
function index(value:unknown){const p=(Array.isArray(value)?value[0]:value) as {dataIndex?:number}|undefined;return p?.dataIndex??0;}
const evolution=computed<EChartsCoreOption>(()=>({
    animation:!window.matchMedia('(prefers-reduced-motion: reduce)').matches,animationDuration:450,
    textStyle:{fontFamily:'inherit'},color:[colors[0]],
    aria:{enabled:true,label:{description:t.chartAccessible(currency.value,days.value.length)}},
    grid:{left:12,right:18,top:24,bottom:days.value.length>31?66:32,containLabel:true},
    tooltip:{trigger:'axis',renderMode:'richText',confine:true,backgroundColor:'#2e2543',textStyle:{color:'#fff',fontSize:13},borderWidth:0,padding:14,formatter:(p:unknown)=>{const d=days.value[index(p)];return d?t.chartDay(date(d.date),amount(d.net_total,currency.value),d.orders):'';}},
    xAxis:{type:'category',boundaryGap:mode.value==='bar',data:days.value.map(d=>d.date),axisLine:{lineStyle:{color:'#e8e4ef'}},axisTick:{show:false},axisLabel:{color:'#82778f',fontSize:11,hideOverlap:true,formatter:date}},
    yAxis:{type:'value',min:0,max:10000,splitNumber:4,axisLabel:{color:'#82778f',fontSize:10,formatter:(value:number)=>amount(decimal(BigInt(Math.round(value))*maximum.value/10000n),currency.value)},splitLine:{lineStyle:{color:'#ede9f2',type:'dashed'}}},
    dataZoom:days.value.length>31?[{type:'inside',filterMode:'none'},{type:'slider',height:16,bottom:4,borderColor:'transparent',showDetail:false,brushSelect:false,handleSize:'120%',dataBackground:{areaStyle:{color:'#ece5f8'}},fillerColor:'rgba(119,85,204,.12)'}]:[],
    series:[{name:t.dailyNet,type:mode.value,data:days.value.map(d=>ratio(d.net_total,maximum.value)),smooth:.25,showSymbol:days.value.length<10,symbolSize:7,lineStyle:{width:3},itemStyle:{borderRadius:[5,5,0,0]},barMaxWidth:32,areaStyle:mode.value==='line'?{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(119,85,204,.28)'},{offset:1,color:'rgba(119,85,204,.01)'}]}}:undefined}],
}));
const distribution=computed<EChartsCoreOption>(()=>({
    animation:!window.matchMedia('(prefers-reduced-motion: reduce)').matches,animationDuration:450,color:colors,
    aria:{enabled:true,label:{description:t.cashSubtitle}},
    tooltip:{trigger:'item',renderMode:'richText',confine:true,backgroundColor:'#2e2543',textStyle:{color:'#fff'},borderWidth:0,formatter:(p:unknown)=>{const m=methods.value[index(p)];return m?t.paymentSplit(t.methods[m.method]||m.method,amount(m.amount,currency.value),(ratio(m.amount,methodTotal.value)/100).toFixed(2)):'';}},
    series:[{type:'pie',radius:['72%','92%'],center:['50%','50%'],padAngle:3,minAngle:2,avoidLabelOverlap:true,label:{show:false},emphasis:{scaleSize:5},itemStyle:{borderRadius:7},data:methods.value.map(m=>({name:t.methods[m.method]||m.method,value:ratio(m.amount,methodTotal.value)}))}],
}));
</script>
<template>
<div class="chart-toolbar" v-if="currencies.length>1"><label>{{ t.chartCurrency }}<select v-model="selected" :aria-label="t.chartCurrency"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select></label></div>
<div class="analytics-charts">
    <section class="chart-panel evolution-panel" :aria-label="t.regions.daily"><header><div><span class="chart-eyebrow">{{ currency }}</span><h3>{{ t.chartTitle }}</h3><p>{{ t.chartSubtitle }}</p></div><div class="chart-toggle" role="group" :aria-label="t.chartType"><button type="button" :aria-pressed="mode==='line'" @click="mode='line'">{{ t.line }}</button><button type="button" :aria-pressed="mode==='bar'" @click="mode='bar'">{{ t.bars }}</button></div></header>
        <VChart v-if="hasDays" class="evolution-chart" :option="evolution" :init-options="{renderer:'svg'}" autoresize :aria-label="t.chartDescription" />
        <div v-else class="chart-empty"><span aria-hidden="true">⌁</span><p>{{ t.chartEmpty }}</p></div>
        <footer><span>{{ t.chartNote }}</span><details v-if="hasDays"><summary>{{ t.dataTable }}</summary><div class="chart-data"><table><thead><tr><th>{{ t.dailyDate }}</th><th>{{ t.dailyOrders }}</th><th>{{ t.dailyNet }}</th></tr></thead><tbody><tr v-for="d in days" :key="d.date"><td>{{ d.date }}</td><td>{{ d.orders }}</td><td>{{ amount(d.net_total,currency) }}</td></tr></tbody></table></div></details></footer>
    </section>
    <section class="chart-panel cash-panel" :aria-label="t.regions.cash"><header><div><span class="chart-eyebrow">{{ currency }}</span><h3>{{ t.cashTitle }}</h3><p>{{ t.cashSubtitle }}</p></div></header>
        <template v-if="cash"><div class="donut-wrap" v-if="methods.length"><VChart class="donut-chart" :option="distribution" :init-options="{renderer:'svg'}" autoresize :aria-label="t.cashSubtitle"/><div class="donut-center" aria-hidden="true"><small>{{ t.cashConfirmed }}</small><strong>{{ amount(cash.confirmed,currency) }}</strong></div></div><p v-else class="cash-no-methods">{{ t.cashNoMethods }}</p>
            <ul class="payment-legend"><li v-for="(m,i) in methods" :key="m.method"><span class="legend-dot" :style="{background:colors[i%colors.length]}"></span><span>{{ t.methods[m.method]||m.method }}</span><strong>{{ amount(m.amount,currency) }}</strong></li></ul>
            <div class="cash-totals"><p><span>{{ t.cashRefunds }}</span><strong>{{ amount(cash.refunded,currency) }}</strong></p><p class="cash-net"><span>{{ t.cashNet }}</span><strong>{{ amount(cash.net_cash,currency) }}</strong></p><p v-if="cash.reversed!=='0.0000'"><span>{{ t.cashReversed }}</span><strong>{{ amount(cash.reversed,currency) }}</strong></p></div>
        </template><div v-else class="chart-empty"><span aria-hidden="true">◌</span><p>{{ t.cashEmpty }}</p></div>
        <footer><details><summary>{{ t.scopeTitle }}</summary><p>{{ t.cashScope }}</p><p v-if="cash?.reversed!=='0.0000'">{{ t.cashReversedNote }}</p></details></footer>
    </section>
</div>
</template>
<style scoped>
.analytics-charts{display:grid;grid-template-columns:minmax(0,1.75fr) minmax(280px,1fr);gap:20px}.chart-panel{min-width:0;background:#fff;border:1px solid #e9e4ef;border-radius:20px;box-shadow:0 3px 16px #30204404;overflow:hidden}.chart-panel header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:24px 24px 0}.chart-panel h3{font-size:18px;margin:4px 0 6px;letter-spacing:-.3px}.chart-panel header p{font-size:13px;color:#82778f;margin:0;line-height:1.5}.chart-eyebrow{font-size:11px;font-weight:700;letter-spacing:1.4px;color:#9377ba}.chart-toggle{display:flex;border:1px solid #ece7f2;padding:3px;border-radius:12px;background:#faf8fc}.chart-toggle button{min-height:44px;min-width:54px;border:0;border-radius:9px;padding:0 10px;font-size:12px;font-weight:650;background:transparent;color:#82778f}.chart-toggle button[aria-pressed=true]{color:#7755cc;background:#fff;box-shadow:0 2px 8px #48326b12}.evolution-chart{height:290px;width:100%;padding:8px 16px 0}.chart-panel footer{border-top:1px solid #f0ecf5;padding:14px 24px;color:#8a7f96;font-size:12px;line-height:1.6}.chart-panel footer>span{display:block;margin-bottom:6px}.chart-panel summary{cursor:pointer;min-height:44px;display:flex;align-items:center;color:#7755cc;font-weight:600}.chart-empty{min-height:280px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:24px;color:#91849e}.chart-empty>span{font-size:60px;color:#d8cde8}.chart-empty p{max-width:260px;line-height:1.6}.donut-wrap{position:relative;width:210px;height:210px;margin:8px auto}.donut-chart{width:100%;height:100%}.donut-center{pointer-events:none;position:absolute;inset:36px;display:flex;flex-direction:column;justify-content:center;text-align:center;gap:8px}.donut-center small{font-size:11px;color:#8a7f96}.donut-center strong{font-size:19px;letter-spacing:-.5px;overflow-wrap:anywhere}.payment-legend{list-style:none;margin:0;padding:0 24px 14px;display:grid;gap:10px}.payment-legend li{display:flex;align-items:center;gap:9px;font-size:12px;color:#796b89}.payment-legend strong{margin-left:auto;color:#42314f;font-weight:650}.legend-dot{width:8px;height:8px;flex-shrink:0;border-radius:3px}.cash-totals{border-top:1px solid #f0ecf5;margin:0 24px;padding:8px 0}.cash-totals p{display:flex;justify-content:space-between;gap:12px;font-size:12px;color:#8a7f96;line-height:1.6}.cash-totals strong{color:#574466;white-space:nowrap}.cash-totals .cash-net{color:#4e3a61;font-weight:650}.cash-totals .cash-net strong{color:#6e4bb6}.cash-no-methods{padding:24px;color:#82778f;font-size:13px}.chart-data{max-height:240px;overflow:auto}.chart-data table{width:100%;text-align:left;border-collapse:collapse}.chart-data th,.chart-data td{padding:8px;border-bottom:1px solid #f0ecf5}.chart-toolbar{display:flex;justify-content:flex-end;margin-bottom:12px}.chart-toolbar label{display:flex;align-items:center;gap:12px;color:#796b89;font-size:13px}.chart-toolbar select{font-size:16px;min-height:48px;min-width:100px}@media(max-width:1050px){.analytics-charts{grid-template-columns:minmax(0,1fr)}.donut-wrap{margin:12px auto}.cash-panel .payment-legend,.cash-totals{max-width:450px;margin-left:auto;margin-right:auto;padding-left:24px;padding-right:24px}.cash-panel header{padding-bottom:10px}}@media(max-width:600px){.chart-panel header{padding:20px 16px 0}.chart-panel footer{padding:12px 16px}.evolution-chart{padding:8px 4px 0;height:260px}.chart-panel h3{font-size:17px}.chart-toggle button{min-width:48px;padding:0 7px}}
</style>
