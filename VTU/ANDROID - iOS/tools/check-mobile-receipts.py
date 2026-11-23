"""Receipt source contracts; native build/device rendering is checked separately."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
checks=0
def check(value,label):
    global checks
    assert value,label
    checks+=1
for brand,pkg in [('DG7-Android','com.datagifting.app'),('JIKABIZ-Android','com.jikabiz.app'),('MZEEVTU-Android','com.mzeevtu')]:
    base=root/brand/'app/src/main'
    java=base/'java'/pkg.replace('.','/')
    check('val receiptDetails: List<ReceiptDetail>' in (java/'data/model/FintechModels.kt').read_text(),brand+' model')
    for rel in ['viewmodel/MainViewModel.kt','ui/transactions/TransactionsFragment.kt']:
        check('m["receipt_details"]' in (java/rel).read_text(),brand+' parser '+rel)
    receipt=(java/'ui/transactions/ReceiptHelper.kt').read_text()
    for fragment in ['tx.receiptDetails.joinToString', 'receiptDescription(tx)', 'measure.breakText', 'prepared.sumOf', 'tx.receiptDetails.map']:
        check(fragment in receipt,brand+' detailed screen/PDF '+fragment)
    check('android:gravity="end" android:maxLines="3"' not in (base/'res/layout/dialog_transaction_receipt.xml').read_text(),brand+' details not truncated to 3 lines')
    history=(java/'ui/transactions/TransactionsFragment.kt').read_text()
    for fragment in ['resp.body()?.get("status") != "success"','Invalid history response','viewLifecycleOwner.lifecycleScope','finally { historyLoading = false }']:
        check(fragment in history,brand+' history '+fragment)
for brand,folder in [('DG7-iOS','DG6-iOS'),('JIKABIZ-iOS','JIKABIZ'),('MZEEVTU-iOS','MZEEVTU-iOS')]:
    base=root/brand/folder/'Views'
    receipt=(base/'MobileReceipt.swift').read_text()
    for fragment in ['receipt_details','boundingRect','height:max(780,height)','for (key,value,height) in rendered','UIGraphicsPDFRenderer','.pngData()']:
        check(fragment in receipt,brand+' detailed exports '+fragment)
    history=(base/'TransactionHistoryView.swift').read_text()
    check('MobileReceiptView(receipt: item)' in history,brand+' history opens receipt')
    if brand!='MZEEVTU-iOS':
        check('body["data"] as? [[String: Any]]' in history,brand+' correct API data array')
        check('hasMore = rows.count == pageSize' in history,brand+' paging')
    else:
        check('func exportToCSV()' in history and 'Apply Filters' in history,brand+' existing filters and CSV retained')
        check('response.status == "success"' in history,brand+' rejects denied history')
print(f'{checks} mobile receipt source checks passed (not device rendering proof)')
