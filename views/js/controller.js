/**
 * Copyright (C) 2021 Merchant's Edition GbR
 * Copyright (C) 2018-2019 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@merchantsedition.com so we can send you a copy immediately.
 *
 * @author    Merchant's Edition <contact@merchantsedition.com>
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2021 Merchant's Edition GbR
 * @copyright 2018-2019 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

/*
 * Upgrade panel.
 */
var coreUpdaterParameters;

$(document).ready(function () {
  if ($('input[name=CORE_UPDATER_PARAMETERS]').length) {
      coreUpdaterParameters = JSON.parse($('input[name=CORE_UPDATER_PARAMETERS]').val());
      coreUpdaterParameters.ignoreTheme
          = $('input[name=CORE_UPDATER_IGNORE_THEME]:checked').val();

      channelChange(true);
      $('#CORE_UPDATER_CHANNEL').on('change', channelChange);

      $('#CORE_UPDATER_VERSION').on('change', versionChange);

      $('input[name=CORE_UPDATER_IGNORE_THEME]').on('change', ignoranceChange);

      $('button[name=coreUpdaterUpdate]').on('click', collectSelectedObsolete);

      addBootstrapCollapser('CORE_UPDATER_PROCESSING', false);

      if (document.getElementById('configuration_fieldset_comparepanel')) {
          $('button[name=coreUpdaterUpdate]').prop('disabled', true);
          processAction('processCompare');
      }
      if (document.getElementById('configuration_fieldset_processpanel')) {
          $('#configuration_fieldset_updatepanel').find('select, button')
              .prop('disabled', true);
          $('button[name=coreUpdaterFinalize]').prop('disabled', true);
          processAction('processUpdate');
      }
  }
  if (document.getElementById('configuration_fieldset_database')) {
      $('#refresh-btn').on('click', function(e) {
          e.preventDefault();
          getDatabaseDifferences();
      });
      setTimeout(getDatabaseDifferences, 100);
  }
});

function channelChange(firstRun) {
  var channelSelect = document.getElementById('CORE_UPDATER_CHANNEL');
  var versionSelect = $('#CORE_UPDATER_VERSION');

  if ( ! channelSelect || ! versionSelect.length) {
    return;
  }

  if (firstRun === true) {
    while (channelSelect.firstChild) {
      channelSelect.removeChild(channelSelect.firstChild);
    }
    for (let i = 0; i < coreUpdaterChannelList.length; i++) {
      let htmlItem = document.createElement('option');
      htmlItem.value = i;
      htmlItem.innerHTML = coreUpdaterChannelList[i].name;
      channelSelect.appendChild(htmlItem);
    }
    channelSelect.value = coreUpdaterChannel;
  }
  versionSelect.empty();
  var channel = coreUpdaterChannelList[channelSelect.selectedIndex];
  $.ajax({
    url: channel.apiUrl,
    type: 'POST',
    data: {'list': channel.channel},
    dataType: 'json',
    success: function(data, status, xhr) {
      if (channel.channel === 'tags') {
        data.reverse();
      }
      data.forEach(function(version) {
          versionSelect.append('<option>'+version+'</option>');
          if (version === coreUpdaterParameters.selectedVersion) {
            versionSelect.val(coreUpdaterParameters.selectedVersion);
          }
      });
      $('#conf_id_CORE_UPDATER_VERSION')
        .find('.help-block')
        .parent()
        .slideUp(200);
      versionSelect.trigger('change');
    },
    error: function(xhr, status, error) {
      var helpText = $('#conf_id_CORE_UPDATER_VERSION').find('.help-block');
      helpText.html(coreUpdaterParameters.errorRetrieval);
      helpText.css('color', 'red');
      console.log('Request to '+channel.apiUrl
                  +' failed with status \''+xhr.state()+'\'.');
    },
  });
}

function versionChange() {
  comparePanelSlide();
}

function ignoranceChange(event) {
  comparePanelSlide();
  doAdminAjax({
    'ajax': true,
    'tab': 'AdminCoreUpdater',
    'action': 'UpdateIgnoreTheme',
    'value': $(this).val()
  });
};

function comparePanelSlide() {
  if ($('#CORE_UPDATER_VERSION').val()
      === coreUpdaterParameters.selectedVersion
      && $('input[name=CORE_UPDATER_IGNORE_THEME]:checked').val()
         === coreUpdaterParameters.ignoreTheme) {
    $('#configuration_fieldset_comparepanel').slideDown(1000);
  } else {
    $('#configuration_fieldset_comparepanel').slideUp(1000);
  }
}

function processAction(action) {
  var url = document.URL+'&action='+action+'&ajax=1';

  $.ajax({
    url: url,
    type: 'POST',
    data: {'compareVersion': coreUpdaterParameters.selectedVersion},
    dataType: 'json',
    success: function(data, status, xhr) {
      if ( ! data) {
        ajaxError('Request to '+url+' succeeded, but returned an empty response.', null);
        return;
      }

      logField = $('textarea[name=CORE_UPDATER_PROCESSING]')[0];
      infoList = data['informations'];
      infoListLength = infoList.length;

      for (i = 0; i < infoListLength; i++) {
        logField.value += "\n";
        if (data['error'] && i === infoListLength - 1) {
          logField.value += "ERROR: ";
          $('#conf_id_CORE_UPDATER_PROCESSING')
            .find('label')
            .css('color', 'red')
            .find('*')
            .contents()
            .filter(function() {
              return this.nodeType === 3 && this.nodeValue.trim !== '';
            })
            [0].data = ' '+coreUpdaterParameters.errorProcessing;
        }
        logField.value += data['informations'][i];
      }

      logField.scrollTop = logField.scrollHeight;

      if (action === 'processCompare' && data['changeset']) {
        changesets = data['changeset'];
        if (changesets['incompatible']) {
          appendChangeset(changesets['incompatible'], 'CORE_UPDATER_INCOMPATIBLE');
          addBootstrapCollapser('CORE_UPDATER_INCOMPATIBLE', true);
        }
        if (changesets['change']) {
          appendChangeset(changesets['change'], 'CORE_UPDATER_UPDATE');
          addBootstrapCollapser('CORE_UPDATER_UPDATE', true);
        }
        if (changesets['add']) {
          appendChangeset(changesets['add'], 'CORE_UPDATER_ADD');
          addBootstrapCollapser('CORE_UPDATER_ADD', true);
        }
        if (changesets['remove']) {
          appendChangeset(changesets['remove'], 'CORE_UPDATER_REMOVE');
          addBootstrapCollapser('CORE_UPDATER_REMOVE', true);
        }
        if (changesets['obsolete']) {
          appendChangeset(changesets['obsolete'], 'CORE_UPDATER_REMOVE_OBSOLETE');
          addBootstrapCollapser('CORE_UPDATER_REMOVE_OBSOLETE', true);
        }
      }

      if (data['done'] === false) {
        processAction(action);
      } else if ( ! data['error']) {
        if (action === 'processCompare') {
          $('#collapsible_CORE_UPDATER_PROCESSING').collapse('hide');
          $('button[name=coreUpdaterUpdate]').prop('disabled', false);
        } else if (action === 'processUpdate') {
          $('button[name=coreUpdaterFinalize]').prop('disabled', false);
        }
        addCompletedText('CORE_UPDATER_PROCESSING',
                         coreUpdaterParameters.completedLog);
      }
    },
    error: function(xhr, status, error) {
      ajaxError('Request to '+url+' failed with status \''+xhr.state()+'\'.', xhr.responseText);
    }
  });

  function ajaxError(message, responseText) {
    addCompletedText('CORE_UPDATER_PROCESSING',
                     coreUpdaterParameters.errorRetrieval);
    $('#conf_id_CORE_UPDATER_PROCESSING')
      .find('label')
      .css('color', 'red')
    console.log(message);
    if (responseText) {
      console.log('Response: ' + responseText);
    }
  }
}

function appendChangeset(changeset, field) {
  var node = $('#conf_id_'+field);

  var html = '<table class="table"><tbody>';

  var count = 0;
  for (line in changeset) {
    count++;
    html += '<tr>'
    if (field !== 'CORE_UPDATER_REMOVE_OBSOLETE') {
      if (changeset[line]) {
        html += '<td>M</td>';
      } else {
        html += '<td>&nbsp;</td>';
      }
    } else {
      html += '<td><input type="checkbox"></td>'
    }
    html += '<td>'+line+'</td>';
    html += '</tr>'
  }
  if ( ! count) {
    html += '<tr><td>-- none --</td></tr>';
  }

  html += '</tbody></table>';

  node.append(html);

  addCompletedText(field, coreUpdaterParameters.completedList, count);
}

function collectSelectedObsolete() {
  var selectedObsolete = [];

  $('#conf_id_CORE_UPDATER_REMOVE_OBSOLETE')
  .find('table')
  .find('tr')
  .filter(function(index, element) {
    if ($(element).find('input').prop('checked')) {
      selectedObsolete.push($(element).find('td:last').html());
    }
  })

  $('input[name=CORE_UPDATER_PARAMETERS]').val(
    JSON.stringify({selectedObsolete: selectedObsolete})
  );

  // Save bandwidth.
  $('textarea[name=CORE_UPDATER_PROCESSING]').val('');
}

function addCompletedText(field, text, number) {
  var element = $('#conf_id_'+field).children('label');
  if (element.children('a').length) {
    element = element.children('a');
  }

  if (number !== undefined) {
    text = text.replace('%d', number);
  }

  var string = element[0].innerHTML.trim();
  var colon = string.slice(-1);
  if (colon === ':') {
    string = string.slice(0, -1);
  }
  string += ' ('+text+')';
  if (colon === ':') {
    string += ':';
  }

  element[0].innerHTML = string;
}

function addBootstrapCollapser(field, initiallyCollapsed) {
  var trigger = $('#conf_id_'+field).children('label');
  if ( ! trigger.length) {
    return;
  }

  var collapsible = $('#conf_id_'+field).children(':last');
  var collapsibleName = 'collapsible_'+field;

  var iconClass = 'icon-collapse-alt';
  if (initiallyCollapsed) {
    iconClass = 'icon-expand-alt';
  }
  trigger.html('<a data-toggle="collapse"'
               +'  data-target="#'+collapsibleName+'"'
               +'  style="color: inherit; text-decoration: inherit;">'
               +'<i class="'+iconClass+'"></i>'
               +' '
               +trigger.html().trim()
               +'</a>'
  );

  collapsible.attr('id', collapsibleName)
             .addClass('collapse');
  if ( ! initiallyCollapsed) {
    collapsible.addClass('in');
  }

  collapsible.on("hide.bs.collapse", function(){
    $(this).siblings('label').find('i').attr('class', 'icon-expand-alt');
  });
  collapsible.on("show.bs.collapse", function(){
    $(this).siblings('label').find('i').attr('class', 'icon-collapse-alt');
  });
}

function getDatabaseDifferences() {
    var element = document.getElementById('db-changes');
    element.className = 'status-running';
    $('#refresh-btn').attr('disabled', 'disabled');
    doAdminAjax(
        {
            'ajax': true,
            'tab': 'AdminCoreUpdater',
            'action': 'GetDatabaseDifferences',
        },
        function(body) {
            var data;
            try {
                data = JSON.parse(body);
            } catch (e) {
                data = {
                  error: e.toString()
                };
            }
            if (data && data.success) {
                getDatabaseDifferencesSuccess(data.differences);
            } else {
                getDatabaseDifferenceFailed(data.error || 'Unknown error');
            }
        },
        function(response) {
            getDatabaseDifferenceFailed('ajax call failed with error code ' + response.status)
        }
    );
}

function getDatabaseDifferenceFailed(error) {
    document.getElementById('db-changes').className = 'status-error';
    document.getElementById('db-error').innerText = error;
    $('#refresh-btn').removeAttr('disabled');
}

function getDatabaseDifferencesSuccess(differences) {
    if (differences && differences.length > 0) {
        var $list = $('#db-changes-list');
        $list.empty();
        differences.forEach(function(diff) {
            var severityInfo = getSeverityInfo(diff.severity);
            $list.append($(
                '<tr class="db-change">' +
                '  <td>' +
                '    <span class="badge '+severityInfo.badge+'" title="'+severityInfo.tooltip+'">'+severityInfo.title+'</span>' +
                '  </td>' +
                '  <td>' +
                '    '+ (diff.destructive ? '<span class="badge badge-danger" title="Fix is potentially dangerous operation that may result in data loss">dangerous</span>' : '') +
                '  </td>' +
                '  <td>' +
                '    '+ replaceTags(diff.description) +
                '  </td>' +
                '  <td class="text-right">' +
                '    '+'<a href="#" data-id="'+diff.id+'" class="btn btn-default"><i class="icon icon-gears"></i> Apply fix</a>'+
                '  </td>' +
                '</tr>'
            ));
        });
        $('#db-changes-list .badge').tooltip();
        $('#db-changes-list .btn').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            applyDatabaseFix([ id ]);

        });
        document.getElementById('db-changes').className = 'status-changes';
    } else {
        document.getElementById('db-changes').className = 'status-no-changes';
    }
    $('#refresh-btn').removeAttr('disabled');
}

function applyDatabaseFix(ids) {
    $('.changes .table').addClass('loading');
    doAdminAjax(
        {
            'ajax': true,
            'tab': 'AdminCoreUpdater',
            'action': 'ApplyDatabaseFix',
            'value': JSON.stringify(ids),
        },
        function(body) {
            $('.changes .table').removeClass('loading');
            var data;
            try {
                data = JSON.parse(body);
            } catch (e) {
                data = {
                    error: e.toString()
                };
            }
            if (data && data.success) {
                getDatabaseDifferencesSuccess(data.differences);
            } else {
                getDatabaseDifferenceFailed(data.error || 'Unknown error');
            }
        },
        function(response) {
            $('.changes .table').removeClass('loading');
            getDatabaseDifferenceFailed('ajax call failed with error code ' + response.status)
        }
    );
}

function getSeverityInfo(severity) {
    switch (severity) {
        case 0:
            return {
                badge: 'module-badge-bought',
                title: 'informational',
                tooltip: 'You should ignore this difference'
            };
        case 1:
            return {
                badge: 'badge-info',
                title: 'recommended',
                tooltip: 'This is not a critical issue, but we still recommend you to fix this',
            };
        case 2:
            return {
                badge: 'badge-danger',
                title: 'critical',
                tooltip: 'This is critical issue and you should fix it immediately. Failure to do so might result in system not working correctly',
            };
    }
}

function replaceTags(str) {
    var output = str;
    var open = new RegExp('\\[[0-9]+\\]', 'g');
    var close = new RegExp('\\[\\/[0-9]+\\]', 'g');
    output = output.replace(open, "<strong>");
    output = output.replace(close, "</strong>");
    return output;
}
