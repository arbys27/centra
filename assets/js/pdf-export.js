(function (global) {
  'use strict';

  function imageData(url) {
    return fetch(url).then(function (response) {
      if (!response.ok) throw new Error('Could not load PDF artwork: ' + url);
      return response.blob();
    }).then(function (blob) {
      return new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onload = function () { resolve(reader.result); };
        reader.onerror = function () { reject(new Error('Could not read PDF artwork.')); };
        reader.readAsDataURL(blob);
      });
    });
  }

  function exportTablePdf(options) {
    if (!global.jspdf || !global.jspdf.jsPDF) {
      throw new Error('The PDF export libraries are not available.');
    }
    if (global.notify) global.notify.loading('Preparing your PDF…');
    var base = document.querySelector('meta[name="csc-base"]');
    var root = base ? base.content : '';
    return Promise.all([
      imageData(root + 'assets/img/csc-header.png'),
      imageData(root + 'assets/img/csc-footer.png')
    ]).then(function (artwork) {
      var doc = new global.jspdf.jsPDF({ orientation: options.orientation || 'portrait', unit: 'mm', format: 'a4' });
      var width = doc.internal.pageSize.getWidth();
      var height = doc.internal.pageSize.getHeight();
      var headerHeight = width * 182 / 897;
      var footerHeight = width * 264 / 965;

      doc.setFontSize(16);
      doc.setTextColor(35, 35, 35);
      doc.text(options.title || '', 12, headerHeight + 8);
      if (options.subtitle) {
        doc.setFontSize(9);
        doc.setTextColor(90, 90, 90);
        doc.text(options.subtitle, 12, headerHeight + 13);
      }
      if (typeof doc.autoTable !== 'function') throw new Error('The PDF table plugin is not available.');
      doc.autoTable({
        head: [options.columns || []],
        body: options.rows || [],
        theme: 'grid',
        startY: headerHeight + 18,
        margin: { top: headerHeight + 18, bottom: footerHeight + 13, left: 12, right: 12 },
        styles: { fontSize: 8, cellPadding: 2.2, lineColor: [170, 170, 170], lineWidth: 0.15 },
        headStyles: { fillColor: [75, 75, 75], textColor: [255, 255, 255], lineColor: [120, 120, 120] }
      });

      var pages = doc.internal.getNumberOfPages();
      var generated = new Date().toLocaleString();
      for (var page = 1; page <= pages; page += 1) {
        doc.setPage(page);
        doc.addImage(artwork[0], 'PNG', 0, 0, width, headerHeight);
        doc.addImage(artwork[1], 'PNG', 0, height - footerHeight, width, footerHeight);
        doc.setFontSize(8);
        doc.setTextColor(45, 45, 45);
        doc.text('Generated: ' + generated, 12, height - 8);
        doc.text('Page ' + page + ' of ' + pages, width - 12, height - 8, { align: 'right' });
      }
      doc.save(options.filename || 'report.pdf');
      if (global.notify) {
        return global.notify.close().then(function () {
          global.notify.success('PDF ready');
        });
      }
    }).catch(function (error) {
      return (global.notify ? global.notify.close() : Promise.resolve()).then(function () {
        throw error;
      });
    });
  }

  global.exportTablePdf = exportTablePdf;
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-export-table-pdf]');
    if (!button) return;
    var table = document.querySelector(button.getAttribute('data-table') || '');
    if (!table) throw new Error('The table to export was not found.');
    var rows = Array.prototype.slice.call(table.querySelectorAll('tr'));
    var columns = rows.length ? Array.prototype.slice.call(rows.shift().querySelectorAll('th,td')).map(function (cell) { return cell.textContent.trim(); }) : [];
    var body = rows.map(function (row) {
      return Array.prototype.slice.call(row.querySelectorAll('th,td')).map(function (cell) { return cell.textContent.trim(); });
    });
    exportTablePdf({
      title: button.getAttribute('data-title') || '',
      subtitle: button.getAttribute('data-subtitle') || '',
      columns: columns,
      rows: body,
      filename: button.getAttribute('data-filename') || 'report.pdf',
      orientation: button.getAttribute('data-orientation') || 'portrait'
    }).catch(function (error) {
      if (global.notify) global.notify.error('PDF export failed', error.message || 'Unable to prepare the PDF.');
      else throw error;
    });
  });
}(window));
