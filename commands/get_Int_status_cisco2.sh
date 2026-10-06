#!/usr/bin/bash
# SNMP community передається з commands.php через env (config/config.php)
comunity=${SNMP_COMMUNITY:?SNMP_COMMUNITY is not set}

if [ -z "$1" ]; then
echo "./get_Int_status_cisco.sh <ip_address> # ip_address like 10.16.11.1"
else
echo -e "Status of $1"
/usr/bin/ping -q -c 2 "$1" > /dev/null
if [ $? -ne 0 ]; then
  echo "================="
  echo "||   No ping   ||"
  echo "================="
  exit
fi
echo -e "Interface\tAdmin\tOper"
echo "-----------------------------------"
/usr/bin/snmptable -v2c -Cf \; -c $comunity $1 IF-MIB::ifTable | grep -v ifDescr | grep -v other | awk -F\; '{print($2,$7,"\t"$8)}' | column -t
fi

#echo -e "\nRoutes:"
#echo -e "10.0.0.0/8:\t=>\t" `/usr/bin/snmpget -v2c -Ovq -c $comunity $1 IP-MIB::ip.21.1.7.10.0.0.0`
#echo -e "0.0.0.0/0:\t=>\t" `/usr/bin/snmpget -v2c -Ovq -c $comunity $1 IP-MIB::ip.21.1.7.0.0.0.0`

echo -e "\nCisco IPaddr:"
/usr/bin/snmpwalk -v2c -Osq -c $comunity $1 IP-MIB::ipAdEntIfIndex | sed s/ipAdEntIfIndex.//g | \
   awk -v cmn=$comunity -v ip=$1 -v OFS='\t' '{cmd="/usr/bin/snmpget -v2c -Ovq -c "cmn" "ip" ifDescr."$2; \
   cmd | getline var; close(cmd); print $1,var }'

echo -e "\nPort\tVlan"
/usr/bin/snmpwalk -v2c -Osq -c $comunity $1 1.3.6.1.4.1.9.9.68.1.2.2.1.2 | awk -F "[. ]" -v ip=$1 -v cmn=$comunity \
   '{cmd1="/usr/bin/snmpget -v2c -Ovq -c "cmn" "ip" ifDescr."$10; \
   cmd2="/usr/bin/snmpget -v2c -Ovq -c "cmn" "ip" 1.3.6.1.4.1.9.9.46.1.3.1.1.4.1."$11; cmd1 | \
   getline var1; close(cmd1); printf var1; cmd2 | getline var2; close(cmd2); printf "\t"var2"("$11")\n"}'

echo -e "\n"
/usr/bin/ping -c 3 $1
