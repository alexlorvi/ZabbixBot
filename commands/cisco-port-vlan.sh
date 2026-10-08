#!/usr/bin/env bash
# Відношення ПОРТ -> VLAN для Cisco ISR 1100 (IOS XE) через SNMP v2c.
#
# Використання:
#   SNMP_COMMUNITY='<community>' ./cisco-port-vlan.sh <ip> [--all] [--csv] [--raw]
#   --raw    — показати сирі відповіді SNMP (для діагностики) і вийти
#   --probe  — перевірити, які гілки MIB пристрій віддає (кількість рядків + перші 3), і вийти
#   ./cisco-port-vlan.sh <ip>            # community буде запитано без ехо
#
# Community НІКОЛИ не записується в скрипт і не потрапляє в аргументи процесу:
# воно кладеться у тимчасовий snmp.conf (chmod 600), який видаляється на виході.
#
# Джерела даних (по порядку):
#   1. CISCO-VLAN-MEMBERSHIP-MIB  vmVlan                 .1.3.6.1.4.1.9.9.68.1.2.2.1.2.<ifIndex>   access-VLAN
#   2. CISCO-VTP-MIB              vlanTrunkPortDynamicStatus .1.3.6.1.4.1.9.9.46.1.6.1.1.14.<ifIndex> 1 = trunk
#                                 vlanTrunkPortNativeVlan   .1.3.6.1.4.1.9.9.46.1.6.1.1.5.<ifIndex>  native VLAN
#                                 vlanTrunkPortVlansEnabled .1.3.6.1.4.1.9.9.46.1.6.1.1.4 (0-1023), .17 (1024-2047),
#                                                           .18 (2048-3071), .19 (3072-4095)  дозволені VLAN, бітова маска
#   3. Q-BRIDGE-MIB (для портів, яких немає в Cisco-таблицях): dot1qVlanStaticEgressPorts .1.3.6.1.2.1.17.7.1.4.3.1.2.<vlan>,
#      dot1qVlanStaticUntaggedPorts .4.3.1.4.<vlan>, dot1qPvid .1.3.6.1.2.1.17.7.1.4.5.1.1, dot1dBasePortIfIndex .1.3.6.1.2.1.17.1.4.1.2
#   4. Назви VLAN: CISCO-VTP-MIB vtpVlanName .1.3.6.1.4.1.9.9.46.1.3.1.1.4.1.<vlan>
#   5. Маршрутизовані порти: VLAN за IP-адресою порту (IP-MIB ipAddrTable .1.3.6.1.2.1.4.20.1) і стандартом
#      10.X.Y.0/24 = 4 x /26: .0 -> VLAN 2, .64 -> VLAN 3, .128 -> VLAN 4, .192 -> VLAN 5 (див. VLAN-мапа)
#   6. L3: SVI — ifName `Vl<N>` на IOS XE (або `Vlan<N>`) і підінтерфейси Gi0/1/0.<N> — за іменем із IF-MIB ifName
set -eo pipefail

HOST=""; SHOW_ALL=0; CSV=0; RAW=0; PROBE=0
for a in "$@"; do
  case "$a" in
    --all) SHOW_ALL=1 ;;
    --csv) CSV=1 ;;
    --raw) RAW=1 ;;
    --probe) PROBE=1 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) HOST="$a" ;;
  esac
done
[[ -n "$HOST" ]] || { echo "Вкажіть IP пристрою: $0 <ip> [--all] [--csv]" >&2; exit 2; }
command -v snmpwalk >/dev/null || { echo "Потрібен пакет snmp (snmpwalk)." >&2; exit 2; }

COMM="${SNMP_COMMUNITY:-}"
if [[ -z "$COMM" ]]; then
  read -rsp "SNMP community для $HOST: " COMM; echo >&2
fi
[[ -n "$COMM" ]] || { echo "Порожнє community." >&2; exit 2; }

CONF_DIR="$(mktemp -d)"; chmod 700 "$CONF_DIR"
trap 'rm -rf "$CONF_DIR"' EXIT
umask 077
printf 'defVersion 2c\ndefCommunity %s\n' "$COMM" > "$CONF_DIR/snmp.conf"
unset COMM SNMP_COMMUNITY
export SNMPCONFPATH="$CONF_DIR" MIBS=""

SNMP=(-t 3 -r 2 -OnQ)

# walk <oid> -> рядки "<індекс>\t<значення>", де індекс — хвіст OID після <oid>.
# Значення, розбиті на кілька рядків (довгі hex-маски), склеюються; крайні лапки знімаються.
walk() {
  local base="$1"
  snmpwalk "${SNMP[@]}" "$HOST" "$base" 2>/dev/null | awk -v b=".${base#.}" '
    function flush() {
      if (oid != "" && index(oid, b ".") == 1) { gsub(/^"|"$/, "", val); print substr(oid, length(b)+2) "\t" val }
      oid = ""
    }
    /^\.[0-9]/ { flush(); i = index($0, " = "); if (!i) next; oid = substr($0, 1, i-1); val = substr($0, i+3); next }
    { val = val " " $0 }
    END { flush() }'
}

# walk_hex: те саме, але OctetString у hex (для бітових масок); усі лапки й переноси прибираються
walk_hex() {
  local base="$1"
  snmpwalk "${SNMP[@]}" -Ox "$HOST" "$base" 2>/dev/null | awk -v b=".${base#.}" '
    function flush() {
      if (oid != "" && index(oid, b ".") == 1) { gsub(/"/, "", val); gsub(/[ \t]+/, " ", val); sub(/^ /, "", val); sub(/ $/, "", val); print substr(oid, length(b)+2) "\t" val }
      oid = ""
    }
    /^\.[0-9]/ { flush(); i = index($0, " = "); if (!i) next; oid = substr($0, 1, i-1); val = substr($0, i+3); next }
    { val = val " " $0 }
    END { flush() }'
}

# merge_ranges "1-1023,1024-2047,5" -> "1-2047,5" (злиття сусідніх діапазонів)
merge_ranges() {
  awk -v s="$1" 'BEGIN {
    n = split(s, a, ","); out = ""; lo = -1; hi = -2
    for (k = 1; k <= n; k++) {
      if (a[k] == "") continue
      m = split(a[k], r, "-"); x = r[1] + 0; y = (m > 1 ? r[2] : r[1]) + 0
      if (x <= hi + 1) { if (y > hi) hi = y }
      else { if (lo >= 0) out = out (out==""?"":",") (lo==hi?lo:lo "-" hi); lo = x; hi = y }
    }
    if (lo >= 0) out = out (out==""?"":",") (lo==hi?lo:lo "-" hi)
    print out }'
}

# bits_to_vlans <зсув> "<hex октети через пробіл>" -> "2,4-5,10"
# Старший біт першого октета = VLAN <зсув>.
bits_to_vlans() {
  local off="$1" hex="$2"
  awk -v off="$off" -v hex="$hex" 'BEGIN {
    n = split(hex, o, /[ :]+/); out = ""; start = -1; prev = -2
    for (k = 1; k <= n; k++) {
      if (o[k] == "") continue
      v = 0; h = toupper(o[k])
      for (c = 1; c <= length(h); c++) v = v*16 + index("0123456789ABCDEF", substr(h, c, 1)) - 1
      for (b = 0; b < 8; b++) if (int(v / (2^(7-b))) % 2 == 1) {
        id = off + (k-1)*8 + b
        if (id == prev + 1) prev = id
        else { if (start >= 0) out = out (out==""?"":",") (start==prev?start:start "-" prev); start = id; prev = id }
      }
    }
    if (start >= 0) out = out (out==""?"":",") (start==prev?start:start "-" prev)
    print out }'
}

# bits_to_list <зсув> "<hex>" -> "1 2 5" (кожен номер окремо; для карти портів bridge, зсув = 1)
bits_to_list() {
  awk -v off="$1" -v hex="$2" 'BEGIN {
    n = split(hex, o, /[ :]+/); out = ""
    for (k = 1; k <= n; k++) {
      if (o[k] == "") continue
      v = 0; h = toupper(o[k])
      for (c = 1; c <= length(h); c++) v = v*16 + index("0123456789ABCDEF", substr(h, c, 1)) - 1
      for (b = 0; b < 8; b++) if (int(v / (2^(7-b))) % 2 == 1) out = out " " (off + (k-1)*8 + b)
    }
    print substr(out, 2) }'
}

# --- перевірка доступності ---
if ! snmpwalk "${SNMP[@]}" "$HOST" 1.3.6.1.2.1.1.1 >/dev/null 2>&1; then
  echo "Немає відповіді SNMP від $HOST (мережа/VPN, ACL на SNMP, community, або SNMP не ввімкнено)." >&2
  exit 1
fi
SYSDESCR="$(snmpwalk "${SNMP[@]}" "$HOST" 1.3.6.1.2.1.1.1.0 2>/dev/null | sed -E 's/^[^=]*= //' | tr '\n' ' ')"

if [[ "$PROBE" -eq 1 ]]; then
  # root|назва — кількість рядків у відповіді показує, чи підтримується гілка
  while IFS='|' read -r root label; do
    out="$(snmpwalk "${SNMP[@]}" "$HOST" "$root" 2>&1 | head -2000)"
    n=$(printf '%s\n' "$out" | grep -c ' = ' || true)
    printf '%-60s %5s рядків\n' "$label ($root)" "$n"
    [[ "$n" -gt 0 ]] && printf '%s\n' "$out" | head -3 | sed 's/^/      /'
  done <<'ROOTS'
1.3.6.1.4.1.9.9.68|CISCO-VLAN-MEMBERSHIP-MIB (вся)
1.3.6.1.4.1.9.9.46.1.3|CISCO-VTP-MIB vtpVlanTable
1.3.6.1.4.1.9.9.46.1.6|CISCO-VTP-MIB vlanTrunkPortTable
1.3.6.1.4.1.9.9.46.1.2|CISCO-VTP-MIB vlanInfo / vtpVlanEdit
1.3.6.1.2.1.17.7.1.4.2|Q-BRIDGE dot1qVlanCurrentTable
1.3.6.1.2.1.17.7.1.4.3|Q-BRIDGE dot1qVlanStaticTable
1.3.6.1.2.1.17.7.1.4.5|Q-BRIDGE dot1qPortVlanTable
1.3.6.1.2.1.17.1.4|BRIDGE-MIB dot1dBasePortTable
1.3.6.1.2.1.2.2.1.8|IF-MIB ifOperStatus
1.3.6.1.4.1.9.9.128|CISCO-VLAN-IFTABLE-RELATIONSHIP-MIB
1.3.6.1.4.1.9.9.215|CISCO-PORT-SECURITY / CISCO-L2-CONTROL-MIB
1.3.6.1.4.1.9.9.87|CISCO-C2900-MIB / CISCO-STACK
1.3.6.1.4.1.9.9.151|CISCO-PRIVATE-VLAN-MIB
ROOTS
  exit 0
fi

if [[ "$RAW" -eq 1 ]]; then
  for o in 1.3.6.1.2.1.31.1.1.1.1 1.3.6.1.4.1.9.9.68.1.2.2.1.2 1.3.6.1.4.1.9.9.46.1.6.1.1.4 1.3.6.1.4.1.9.9.46.1.6.1.1.5 \
           1.3.6.1.4.1.9.9.46.1.6.1.1.13 1.3.6.1.4.1.9.9.46.1.6.1.1.14 1.3.6.1.4.1.9.9.46.1.6.1.1.17 1.3.6.1.2.1.4.20.1.2 1.3.6.1.2.1.4.20.1.3 1.3.6.1.2.1.17.7.1.4.5.1.1; do
    echo "### $o"; snmpwalk "${SNMP[@]}" -Ox "$HOST" "$o" 2>&1 | head -40
  done
  exit 0
fi

declare -A NAME ALIAS OPER VM DYN NATIVE ALLOWED PVID BP2IF VNAME IPS IPMASK IFOF QUNT QTAG DSTATE

while IFS=$'\t' read -r i v; do NAME[$i]="$v";  done < <(walk 1.3.6.1.2.1.31.1.1.1.1)    # ifName
while IFS=$'\t' read -r i v; do ALIAS[$i]="$v"; done < <(walk 1.3.6.1.2.1.31.1.1.1.18)   # ifAlias (опис порту)
while IFS=$'\t' read -r i v; do OPER[$i]="$v";  done < <(walk 1.3.6.1.2.1.2.2.1.8)       # ifOperStatus (1=up)
while IFS=$'\t' read -r i v; do VM[$i]="$v";    done < <(walk 1.3.6.1.4.1.9.9.68.1.2.2.1.2)   # vmVlan
while IFS=$'\t' read -r i v; do DYN[$i]="$v";   done < <(walk 1.3.6.1.4.1.9.9.46.1.6.1.1.14)  # trunk status
while IFS=$'\t' read -r i v; do NATIVE[$i]="$v"; done < <(walk 1.3.6.1.4.1.9.9.46.1.6.1.1.5)
while IFS=$'\t' read -r i v; do DSTATE[$i]="$v"; done < <(walk 1.3.6.1.4.1.9.9.46.1.6.1.1.13)  # vlanTrunkPortDynamicState: 1 on, 2 off(access), 3 desirable, 4 auto, 5 onNoNegotiate # native VLAN
while IFS=$'\t' read -r i v; do VNAME[${i#1.}]="$v"; done < <(walk 1.3.6.1.4.1.9.9.46.1.3.1.1.4) # vtpVlanName (індекс 1.<vlan>)

# дозволені VLAN на транках: чотири бітові маски
for pair in "4:0" "17:1024" "18:2048" "19:3072"; do
  col="${pair%%:*}"; off="${pair##*:}"
  while IFS=$'\t' read -r i v; do
    list="$(bits_to_vlans "$off" "$v")"
    [[ -n "$list" ]] && ALLOWED[$i]="${ALLOWED[$i]:+${ALLOWED[$i]},}$list"
  done < <(walk_hex "1.3.6.1.4.1.9.9.46.1.6.1.1.$col")
done
for i in "${!ALLOWED[@]}"; do
  m="$(merge_ranges "${ALLOWED[$i]}")"
  [[ "$m" == "1-4095" || "$m" == "1-4094" || "$m" == "0-4095" ]] && m="усі (1-4094)"
  ALLOWED[$i]="$m"
done

# Q-BRIDGE-MIB (завжди; використовується для портів, яких немає в Cisco-таблицях):
#   dot1dBasePortIfIndex, dot1qPvid, dot1qVlanStaticEgressPorts (.4.3.1.2), dot1qVlanStaticUntaggedPorts (.4.3.1.4)
while IFS=$'\t' read -r i v; do BP2IF[$i]="$v"; done < <(walk 1.3.6.1.2.1.17.1.4.1.2)
while IFS=$'\t' read -r i v; do [[ -n "${BP2IF[$i]:-}" ]] && PVID[${BP2IF[$i]}]="$v"; done < <(walk 1.3.6.1.2.1.17.7.1.4.5.1.1)
declare -A QEGR QUNTV
while IFS=$'\t' read -r vl v; do QEGR[$vl]="$(bits_to_list 1 "$v")"; done < <(walk_hex 1.3.6.1.2.1.17.7.1.4.3.1.2)
while IFS=$'\t' read -r vl v; do QUNTV[$vl]="$(bits_to_list 1 "$v")"; done < <(walk_hex 1.3.6.1.2.1.17.7.1.4.3.1.4)
if [[ ${#QEGR[@]} -eq 0 ]]; then
  while IFS=$'\t' read -r vl v; do QEGR[${vl##*.}]="$(bits_to_list 1 "$v")"; done < <(walk_hex 1.3.6.1.2.1.17.7.1.4.2.1.4)   # dot1qVlanCurrentEgressPorts
  while IFS=$'\t' read -r vl v; do QUNTV[${vl##*.}]="$(bits_to_list 1 "$v")"; done < <(walk_hex 1.3.6.1.2.1.17.7.1.4.2.1.5)  # dot1qVlanCurrentUntaggedPorts
fi
for vl in "${!QEGR[@]}"; do
  for bp in ${QEGR[$vl]}; do
    ifx="${BP2IF[$bp]:-}"; [[ -n "$ifx" ]] || continue
    if [[ " ${QUNTV[$vl]:-} " == *" $bp "* ]]; then QUNT[$ifx]="${QUNT[$ifx]:+${QUNT[$ifx]},}$vl"
    else QTAG[$ifx]="${QTAG[$ifx]:+${QTAG[$ifx]},}$vl"; fi
  done
done

# IP-адреси інтерфейсів: індекс таблиці = IP, значення = ifIndex / маска
while IFS=$'\t' read -r ip v; do IFOF[$ip]="$v"; done < <(walk 1.3.6.1.2.1.4.20.1.2)
while IFS=$'\t' read -r ip v; do IPMASK[$ip]="$v"; done < <(walk 1.3.6.1.2.1.4.20.1.3)

# vlan_by_ip <ip> <mask> -> номер VLAN за стандартом 4 x /26, або "-"
mask_to_prefix() {
  local m="$1" o bits=0; local IFS=.
  for o in $m; do case $o in 255) bits=$((bits+8));; 254) bits=$((bits+7));; 252) bits=$((bits+6));; 248) bits=$((bits+5));; 240) bits=$((bits+4));; 224) bits=$((bits+3));; 192) bits=$((bits+2));; 128) bits=$((bits+1));; esac; done
  echo "$bits"
}
vlan_by_ip() {
  local ip="$1" mask="$2" o4="${1##*.}"
  [[ "$mask" == "255.255.255.192" ]] || { echo "-"; return; }
  case $(( o4 & 192 )) in 0) echo 2 ;; 64) echo 3 ;; 128) echo 4 ;; 192) echo 5 ;; esac
}
for ip in "${!IFOF[@]}"; do
  i="${IFOF[$ip]}"; mask="${IPMASK[$ip]:--}"; v="$(vlan_by_ip "$ip" "$mask")"
  [[ "$v" == "-" ]] && info="$ip/$(mask_to_prefix "$mask")" || info="$ip/26 -> VLAN $v"
  IPS[$i]="${IPS[$i]:+${IPS[$i]}, }$info"
done

vname() { local n="${VNAME[$1]:-}"; [[ -n "$n" ]] && printf '%s' "$n" || printf '-'; }

OUT="$(mktemp)"
{
  printf 'ifIndex\tПорт\tСтан\tРежим\tVLAN (access/native)\tНазва VLAN\tДозволені VLAN\tIP (VLAN за адресою)\tОпис\n'
  for i in $(printf '%s\n' "${!NAME[@]}" | sort -n); do
    n="${NAME[$i]}"
    # фізичні порти; з --all — і решта (loopback, tunnel тощо), але без Null/підінтерфейсів
    [[ "$SHOW_ALL" -eq 1 || "$n" =~ ^(Gi|Fa|Te|Tw|Fo|Eth|Po)[0-9]+(/[0-9]+)*$ ]] || continue
    st="down"; [[ "${OPER[$i]:-}" == "1" ]] && st="up"
    mode="-"; vlan="-"; allowed="-"
    if [[ "${DYN[$i]:-}" == "1" ]]; then
      mode="trunk"; vlan="${NATIVE[$i]:--}"; allowed="${ALLOWED[$i]:--}"
    elif [[ -n "${VM[$i]:-}" ]]; then
      mode="access"; vlan="${VM[$i]}"
    elif [[ -n "${DYN[$i]:-}" && -z "${QUNT[$i]:-}${QTAG[$i]:-}" ]]; then
      # порт є в vlanTrunkPortTable, але не транкує. access-VLAN по SNMP тут не віддається (vmVlan відсутній);
      # native=1 і «усі VLAN» у цій таблиці — значення за замовчуванням, а не доказ приналежності до VLAN.
      case "${DSTATE[$i]:-}" in 2) mode="switchport access" ;; 3) mode="switchport dynamic desirable" ;; 4) mode="switchport dynamic auto" ;; 1|5) mode="trunk (не піднявся)" ;; *) mode="switchport, не транк" ;; esac
      vlan="н/д"; allowed="-"
    elif [[ -n "${QUNT[$i]:-}" || -n "${QTAG[$i]:-}" ]]; then
      mode="q-bridge"; vlan="${QUNT[$i]:-${PVID[$i]:--}}"; allowed="${QTAG[$i]:--}"
    elif [[ -n "${PVID[$i]:-}" ]]; then
      mode="pvid"; vlan="${PVID[$i]}"
    elif [[ -n "${IPS[$i]:-}" ]]; then
      mode="routed/L3"
    else
      mode="? (VLAN не віддається по SNMP)"
    fi
    nm="-"; [[ "$vlan" != "-" ]] && nm="$(vname "$vlan")"
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$i" "$n" "$st" "$mode" "$vlan" "$nm" "$allowed" "${IPS[$i]:--}" "${ALIAS[$i]:--}"
  done
} > "$OUT"

# L3: SVI Vlan<N> і підінтерфейси <порт>.<N>
L3="$(mktemp)"
{
  printf 'ifIndex\tІнтерфейс\tСтан\tТип\tVLAN (з імені)\tНазва VLAN\tIP\n'
  for i in $(printf '%s\n' "${!NAME[@]}" | sort -n); do
    n="${NAME[$i]}"; st="down"; [[ "${OPER[$i]:-}" == "1" ]] && st="up"
    if [[ "$n" =~ ^(Vlan|Vl)([0-9]+)$ ]];            then printf '%s\t%s\t%s\tSVI\t%s\t%s\t%s\n' "$i" "$n" "$st" "${BASH_REMATCH[2]}" "$(vname "${BASH_REMATCH[2]}")" "${IPS[$i]:--}"
    elif [[ "$n" =~ ^[A-Za-z]+[0-9/]+\.([0-9]+)$ ]]; then printf '%s\t%s\t%s\tпідінтерфейс (мітка з імені, перевірте encapsulation)\t%s\t%s\t%s\n' "$i" "$n" "$st" "${BASH_REMATCH[1]}" "$(vname "${BASH_REMATCH[1]}")" "${IPS[$i]:--}"
    fi
  done
} > "$L3"

echo "# $HOST — ${SYSDESCR:0:120}" >&2
[[ ${#VM[@]} -eq 0 ]] && echo "# Примітка: access-VLAN портів без транка по SNMP на цій платформі не віддається (vmVlan відсутній); \"н/д\" = невідомо, звіряйте з show running-config." >&2
if [[ "$CSV" -eq 1 ]]; then
  tr '\t' ';' < "$OUT"
  [[ $(wc -l < "$L3") -gt 1 ]] && { echo; tr '\t' ';' < "$L3"; }
else
  column -t -s $'\t' "$OUT"
  if [[ $(wc -l < "$L3") -gt 1 ]]; then echo; echo "L3-інтерфейси:"; column -t -s $'\t' "$L3"; fi
fi
rm -f "$OUT" "$L3"
