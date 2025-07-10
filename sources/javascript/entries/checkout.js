document.addEventListener('DOMContentLoaded', () => {
  let invoiceCheckbox = document.querySelectorAll('.js-fv-checkbox');
  let invoiceFields = document.querySelectorAll('.js-fvat-to-show');
  let receiptFields = document.querySelectorAll('.js-fvat-to-hide');

  if (invoiceCheckbox.length > 0) {
    if (invoiceFields.length > 0 && receiptFields.length > 0) {
      invoiceFields.forEach(fld => {
        fld.style.display = 'none';
      });
      invoiceCheckbox.forEach(chb => {
        chb.addEventListener('change', event => {
          if (event.target.value === '1' && event.target.checked) {
            invoiceFields.forEach(fld => {
              fld.style.display = 'block';
            })
            receiptFields.forEach(fld => {
              fld.style.display = 'none';
            })
          } else {
            invoiceFields.forEach(fld => {
              fld.style.display = 'none';
            })
            receiptFields.forEach(fld => {
              fld.style.display = 'block';
            })
          }
        })
      })
    }
  }
})
